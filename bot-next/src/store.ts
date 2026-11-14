import { DatabaseSync } from 'node:sqlite';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';
import type { TelegramMessage, TelegramUpdate } from './telegram.js';

export function openDatabase(directory: string): DatabaseSync {
  mkdirSync(directory, { recursive: true, mode: 0o700 });
  const db = new DatabaseSync(join(directory, 'bot.sqlite'));
  db.exec('PRAGMA journal_mode=WAL; PRAGMA synchronous=FULL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON');
  return db;
}

export interface InboxItem { id: number; chat: string; payload: string; decision: string | null; status: string }
export interface ConversationEvent { message: TelegramMessage; updateId?: number; kind: 'message' | 'edited message' | 'confirmed bot output' }
export class Store {
  constructor(readonly db: DatabaseSync) {
    db.exec(`CREATE TABLE IF NOT EXISTS ingress(id INTEGER PRIMARY KEY, chat TEXT NOT NULL, payload TEXT NOT NULL, decision TEXT, status TEXT NOT NULL DEFAULT 'pending');
      CREATE INDEX IF NOT EXISTS ingress_chat_id ON ingress(chat,id);
      CREATE TABLE IF NOT EXISTS settings(key TEXT PRIMARY KEY, value TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS outgoing(id TEXT PRIMARY KEY, chat TEXT NOT NULL, method TEXT NOT NULL, payload TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending', result TEXT);
      CREATE TABLE IF NOT EXISTS telegram_calls(id TEXT PRIMARY KEY, chat TEXT NOT NULL, request_hash TEXT NOT NULL, status TEXT NOT NULL, result TEXT);
      CREATE TABLE IF NOT EXISTS reply_parts(reply_id TEXT NOT NULL, part INTEGER NOT NULL, outgoing_id TEXT NOT NULL UNIQUE, PRIMARY KEY(reply_id,part));
      CREATE TABLE IF NOT EXISTS chat_entities(id TEXT PRIMARY KEY, type TEXT, title TEXT, active INTEGER NOT NULL DEFAULT 1, first_seen_at INTEGER NOT NULL, last_seen_at INTEGER NOT NULL);`);
  }
  /** Only the process holding the owner lock may perform startup recovery. */
  recoverInterrupted(): void { this.db.exec("UPDATE outgoing SET status='unknown' WHERE status='sending'; UPDATE telegram_calls SET status='unknown' WHERE status='running'; UPDATE ingress SET status='pending' WHERE status='processing';"); }
  get(key: string): string | undefined { return (this.db.prepare('SELECT value FROM settings WHERE key=?').get(key) as { value: string } | undefined)?.value; }
  set(key: string, value: string): void { this.db.prepare('INSERT INTO settings VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value').run(key, value); }
  /** Omitted filter admits every chat delivered by Telegram. [] deliberately admits none. */
  ingest(update: TelegramUpdate, allowed?: readonly string[]): boolean {
    const msg = update.message ?? update.edited_message;
    const entity = msg?.chat ?? update.my_chat_member?.chat ?? update.message_reaction?.chat;
    const chat = entity ? String(entity.id) : '';
    this.db.exec('BEGIN IMMEDIATE');
    try {
      let inserted = false;
      if (chat && (allowed === undefined || allowed.includes(chat))) {
        const membership = update.my_chat_member?.new_chat_member.status;
        const active = membership === 'left' || membership === 'kicked' ? 0 : 1;
        const details = entity as { type?: string; title?: string };
        this.db.prepare(`INSERT INTO chat_entities VALUES(?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET
          type=COALESCE(excluded.type,chat_entities.type), title=COALESCE(excluded.title,chat_entities.title),
          active=excluded.active, last_seen_at=excluded.last_seen_at`).run(chat, details.type ?? null, details.title ?? null, active, Date.now(), Date.now());
        if (!update.my_chat_member) inserted = Number(this.db.prepare('INSERT OR IGNORE INTO ingress(id,chat,payload) VALUES(?,?,?)').run(update.update_id, chat, JSON.stringify(update)).changes) > 0;
      }
      const cursor = Number(this.get('offset') ?? 0);
      this.set('offset', String(Math.max(cursor, update.update_id + 1)));
      this.db.exec('COMMIT'); return inserted;
    } catch (error) { this.db.exec('ROLLBACK'); throw error; }
  }
  pending(): InboxItem[] { return this.db.prepare("SELECT * FROM ingress WHERE status='pending' ORDER BY id LIMIT 100").all() as unknown as InboxItem[]; }
  status(id: number, status: string): void { this.db.prepare('UPDATE ingress SET status=? WHERE id=?').run(status, id); }
  decide(id: number, decision: unknown): void { this.db.prepare('UPDATE ingress SET decision=? WHERE id=? AND decision IS NULL').run(JSON.stringify(decision), id); }
  recent(chat: string, limit = 30, beforeUpdateId = Number.MAX_SAFE_INTEGER): TelegramMessage[] {
    return this.recentEvents(chat, limit, beforeUpdateId).map(event => event.message);
  }
  private recentEvents(chat: string, limit: number, beforeUpdateId: number): ConversationEvent[] {
    const rows = this.db.prepare('SELECT payload FROM ingress WHERE chat=? AND id<=? ORDER BY id DESC LIMIT ?').all(chat, beforeUpdateId, limit) as { payload: string }[];
    const latest = new Map<number, ConversationEvent>();
    for (const row of rows.reverse()) {
      const update = JSON.parse(row.payload) as TelegramUpdate, message = update.message ?? update.edited_message;
      if (message && !message.successful_payment && !message.refunded_payment) latest.set(message.message_id, { message, updateId: update.update_id, kind: update.edited_message ? 'edited message' : 'message' });
    }
    return [...latest.values()];
  }
  conversation(chat: string, beforeUpdateId: number, limit = 30): TelegramMessage[] {
    return this.conversationEvents(chat, beforeUpdateId, limit).map(event => event.message);
  }
  conversationEvents(chat: string, beforeUpdateId: number, limit = 30): ConversationEvent[] {
    const incoming = this.recentEvents(chat, limit, beforeUpdateId);
    const cutoff = Math.max(...incoming.map(event => event.message.date));
    const sent = this.db.prepare("SELECT result FROM outgoing WHERE chat=? AND method='sendMessage' AND status='sent' ORDER BY rowid DESC LIMIT ?").all(chat, limit) as { result: string | null }[];
    const messages = new Map(incoming.map(event => [event.message.message_id, event]));
    for (const row of sent) {
      const message = row.result ? JSON.parse(row.result) as TelegramMessage : undefined;
      if (message?.from?.is_bot && String(message.chat?.id) === chat && message.text && message.date <= cutoff) messages.set(message.message_id, { message, kind: 'confirmed bot output' });
    }
    const calls = this.db.prepare("SELECT result FROM telegram_calls WHERE chat=? AND status='done' ORDER BY rowid DESC LIMIT ?").all(chat, limit) as { result: string }[];
    for (const row of calls.reverse()) {
      const result = (JSON.parse(row.result) as { result: unknown }).result;
      for (const value of Array.isArray(result) ? result : [result]) {
        const message = value as TelegramMessage | null;
        if (message && Number.isSafeInteger(message.message_id) && String(message.chat?.id) === chat && message.date <= cutoff && !message.successful_payment && !message.refunded_payment) messages.set(message.message_id, { message, kind: 'confirmed bot output' });
      }
    }
    const floor = Number(this.get(`memory:context-floor:${chat}`) ?? 0);
    return [...messages.values()].filter(event => event.message.message_id > floor).sort((a, b) => a.message.date - b.message.date || a.message.message_id - b.message.message_id).slice(-limit);
  }
  queue(id: string, chat: string, method: string, payload: Record<string, unknown>): void {
    const body = JSON.stringify({ ...payload, chat_id: chat });
    const existing = this.db.prepare('SELECT payload,method,chat FROM outgoing WHERE id=?').get(id) as { payload: string; method: string; chat: string } | undefined;
    if (existing && (existing.payload !== body || existing.method !== method || existing.chat !== chat)) throw new Error('Outgoing ID reused with a different payload');
    this.db.prepare('INSERT OR IGNORE INTO outgoing(id,chat,method,payload) VALUES(?,?,?,?)').run(id, chat, method, body);
  }
  outgoing(): { id: string; chat: string; method: string; payload: string }[] {
    return this.db.prepare(`SELECT o.id,o.chat,o.method,o.payload FROM outgoing o WHERE o.status='pending' AND NOT EXISTS (
      SELECT 1 FROM reply_parts part JOIN reply_parts prior ON prior.reply_id=part.reply_id AND prior.part<part.part
      JOIN outgoing previous ON previous.id=prior.outgoing_id WHERE part.outgoing_id=o.id AND previous.status!='sent'
    ) ORDER BY o.rowid LIMIT 20`).all() as never;
  }
  claimOutgoing(id: string): boolean { return Number(this.db.prepare("UPDATE outgoing SET status='sending' WHERE id=? AND status='pending'").run(id).changes) === 1; }
  outgoingStatus(id: string, status: string, result?: unknown): void { this.db.prepare('UPDATE outgoing SET status=?,result=? WHERE id=?').run(status, result === undefined ? null : JSON.stringify(result), id); }
}
