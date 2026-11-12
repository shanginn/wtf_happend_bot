import { createHash } from 'node:crypto';
import { atomic } from './budget.js';
import type { Store } from './store.js';

export interface ReplyRoute { chat: string; messageId?: number; topic?: number }
export interface ReplyContext { version: 1; id: string }
type Row = { id: string; chat: string; scope: string; request_id: string; route: string; ingress_id: number | null; committed: number; outcome: string | null; final_text: string | null; legacy_message: string | null; blocked: string | null };
const digest = (value: unknown) => createHash('sha256').update(JSON.stringify(value)).digest('hex');
export function replyId(scope: string, requestId: string): string { return `reply:${digest([scope, requestId])}`; }
export class ReplyProtocolError extends Error { constructor(readonly code: string) { super(code); } }

/** One canonical final and one transactional delivery plan per admitted input. Drafts never perform HTTP. */
export class Replies {
  constructor(readonly store: Store) {
    store.db.exec(`CREATE TABLE IF NOT EXISTS reply_turns (
      id TEXT PRIMARY KEY, chat TEXT NOT NULL, scope TEXT NOT NULL, request_id TEXT NOT NULL, route TEXT NOT NULL,
      ingress_id INTEGER, committed INTEGER NOT NULL DEFAULT 0, outcome TEXT, final_text TEXT, legacy_message TEXT, blocked TEXT,
      UNIQUE(scope,request_id));
      CREATE TABLE IF NOT EXISTS reply_drafts (id TEXT PRIMARY KEY, reply_id TEXT NOT NULL, request_hash TEXT NOT NULL, payload TEXT NOT NULL, receipt TEXT NOT NULL);
      CREATE TABLE IF NOT EXISTS reply_parts (reply_id TEXT NOT NULL, part INTEGER NOT NULL, outgoing_id TEXT NOT NULL UNIQUE, PRIMARY KEY(reply_id,part));`);
  }
  /** Adopt only old queued/unfinished canonical deliveries by their producer IDs, before any worker starts. */
  recoverLegacyOutbox(): number {
    return atomic(this.store.db, () => {
      const rows = this.store.db.prepare(`SELECT o.id,o.status,o.payload,o.chat,i.id AS ingress_id,i.payload AS input
        FROM outgoing o JOIN ingress i ON o.id='answer:'||i.id OR o.id='failure:'||i.id
        WHERE (i.status IN ('pending','processing') OR EXISTS (
          SELECT 1 FROM outgoing sibling WHERE (sibling.id='answer:'||i.id OR sibling.id='failure:'||i.id)
          AND sibling.status IN ('pending','sending','unknown') AND NOT EXISTS(SELECT 1 FROM reply_parts WHERE outgoing_id=sibling.id)
        ))
        AND NOT EXISTS(SELECT 1 FROM reply_parts WHERE outgoing_id=o.id) ORDER BY o.rowid`).all() as { id: string; status: string; payload: string; chat: string; ingress_id: number; input: string }[];
      const groups = new Map<number, typeof rows>();
      for (const row of rows) { const group = groups.get(row.ingress_id) ?? []; group.push(row); groups.set(row.ingress_id, group); }
      let adopted = 0;
      for (const [inputId, group] of groups) {
        const selected = group.find(row => row.id === `answer:${inputId}` && row.status !== 'rejected') ?? group.find(row => row.id === `failure:${inputId}`) ?? group[0]!;
        const update = JSON.parse(selected.input), message = update.message ?? update.edited_message;
        if (!message || String(message.chat?.id) !== selected.chat) throw new ReplyProtocolError('LEGACY_OUTBOX_ROUTE_MISMATCH');
        const context = this.open(selected.chat, `telegram:${inputId}`, { chat: selected.chat, messageId: message.message_id, ...(message.message_thread_id === undefined ? {} : { topic: message.message_thread_id }) }, inputId);
        if (this.row(context.id)!.committed) {
          for (const row of group) this.store.db.prepare("UPDATE outgoing SET status='superseded' WHERE id=? AND status='pending'").run(row.id);
          continue;
        }
        const payload = JSON.parse(selected.payload) as { text?: unknown };
        if (typeof payload.text !== 'string') throw new ReplyProtocolError('LEGACY_OUTBOX_TEXT_MISSING');
        const outcome = selected.id.startsWith('answer:') ? 'done' : 'failed';
        this.store.db.prepare('UPDATE reply_turns SET committed=1,outcome=?,final_text=? WHERE id=?').run(outcome, payload.text, context.id);
        this.store.db.prepare('INSERT INTO reply_parts VALUES(?,?,?)').run(context.id, 0, selected.id);
        for (const row of group) if (row.id !== selected.id) this.store.db.prepare("UPDATE outgoing SET status='superseded' WHERE id=? AND status='pending'").run(row.id);
        this.store.status(inputId, outcome); adopted++;
      }
      this.store.set('reply-protocol-version', '1');
      return adopted;
    });
  }
  open(scope: string, requestId: string, route: ReplyRoute, ingressId?: number): ReplyContext {
    const id = replyId(scope, requestId), value = JSON.stringify({ chat: route.chat, ...(route.messageId === undefined ? {} : { messageId: route.messageId }), ...(route.topic === undefined ? {} : { topic: route.topic }) }), old = this.row(id);
    if (old && (old.chat !== route.chat || old.route !== value || old.ingress_id !== (ingressId ?? null))) throw new ReplyProtocolError('REPLY_IDENTITY_CONFLICT');
    this.store.db.prepare('INSERT OR IGNORE INTO reply_turns(id,chat,scope,request_id,route,ingress_id) VALUES(?,?,?,?,?,?)').run(id, route.chat, scope, requestId, value, ingressId ?? null);
    return { version: 1, id };
  }
  row(id: string): Row | undefined { return this.store.db.prepare('SELECT * FROM reply_turns WHERE id=?').get(id) as Row | undefined; }
  draft(context: ReplyContext, operation: string, chat: string, method: string, parameters: Record<string, unknown>) {
    const row = this.row(context.id);
    if (!row || row.chat !== chat) throw new ReplyProtocolError('REPLY_CONTEXT_MISMATCH');
    if (method !== 'sendMessage') throw new ReplyProtocolError('REPLY_DRAFT_REQUIRES_PLAIN_SEND_MESSAGE_USE_ACTION_FOR_LITERAL_API');
    if (String(parameters.chat_id) !== row.chat) throw new ReplyProtocolError('REPLY_TARGET_MISMATCH_USE_ACTION_FOR_OTHER_DESTINATIONS');
    const route = JSON.parse(row.route) as ReplyRoute;
    if (parameters.message_thread_id !== undefined && parameters.message_thread_id !== route.topic) throw new ReplyProtocolError('REPLY_TOPIC_MISMATCH');
    if (parameters.reply_parameters !== undefined) {
      const target = parameters.reply_parameters as { message_id?: unknown };
      if (target?.message_id !== route.messageId) throw new ReplyProtocolError('REPLY_SOURCE_MISMATCH');
    }
    if (typeof parameters.text !== 'string' || !parameters.text.trim()) throw new ReplyProtocolError('REPLY_DRAFT_TEXT_REQUIRED');
    // A reply is plain final text. Literal formatting/entities/keyboards remain available through purpose=action.
    if (Object.keys(parameters).some(key => !['chat_id', 'text', 'message_thread_id', 'reply_parameters'].includes(key))) throw new ReplyProtocolError('REPLY_DRAFT_OPTIONS_REQUIRE_EXPLICIT_ACTION');
    const hash = digest({ method, parameters }), previous = this.store.db.prepare('SELECT reply_id,request_hash,receipt FROM reply_drafts WHERE id=?').get(operation) as { reply_id: string; request_hash: string; receipt: string } | undefined;
    if (previous) {
      if (previous.reply_id !== row.id || previous.request_hash !== hash) throw new ReplyProtocolError('REPLY_DRAFT_OPERATION_CONFLICT');
      return JSON.parse(previous.receipt) as { ok: true; delivery: 'draft'; reply_id: string; sent: false };
    }
    if (row.committed) throw new ReplyProtocolError('REPLY_ALREADY_COMMITTED');
    const receipt = { ok: true as const, delivery: 'draft' as const, reply_id: row.id, sent: false as const };
    this.store.db.prepare('INSERT INTO reply_drafts VALUES(?,?,?,?,?)').run(operation, row.id, hash, JSON.stringify({ method, parameters }), JSON.stringify(receipt));
    return receipt;
  }
  /** Explicit v0 recovery adapter: a known old reply is edited in place, never copied or used to discard the final. */
  adoptLegacy(context: ReplyContext, messages: readonly { message_id: number; chat: { id: number }; text?: string }[], uncertain: boolean): void {
    atomic(this.store.db, () => {
      const row = this.row(context.id);
      if (!row || row.committed) return;
      const anchors = new Map(messages.filter(message => message?.chat && Number.isSafeInteger(message.message_id) && String(message.chat.id) === row.chat).map(message => [message.message_id, message]));
      if (uncertain || anchors.size > 1) this.store.db.prepare('UPDATE reply_turns SET blocked=? WHERE id=?').run(uncertain ? 'LEGACY_DELIVERY_UNKNOWN' : 'LEGACY_MULTIPLE_DELIVERIES_REQUIRE_RECONCILIATION', row.id);
      else if (anchors.size === 1) this.store.db.prepare('UPDATE reply_turns SET legacy_message=? WHERE id=?').run(JSON.stringify([...anchors.values()][0]), row.id);
    });
  }
  complete(context: ReplyContext, finalText: string, outcome: 'done' | 'failed' = 'done'): void {
    atomic(this.store.db, () => {
      const row = this.row(context.id);
      if (!row) throw new ReplyProtocolError('REPLY_NOT_FOUND');
      if (row.committed) {
        if (row.final_text !== finalText || row.outcome !== outcome) throw new ReplyProtocolError('FINAL_REPLY_IMMUTABLE');
        return;
      }
      if (outcome === 'done' && !finalText.trim() && this.store.db.prepare('SELECT 1 FROM reply_drafts WHERE reply_id=? LIMIT 1').get(row.id)) throw new ReplyProtocolError('FINAL_REPLY_REQUIRED_AFTER_DRAFT');
      const route = JSON.parse(row.route) as ReplyRoute;
      const anchor = row.legacy_message ? JSON.parse(row.legacy_message) as { message_id: number; text?: string } : undefined;
      if (!row.blocked) for (const [part, text] of splitReply(finalText).entries()) {
        const id = `${row.id}:part:${part}`, editing = part === 0 && anchor !== undefined;
        const payload = editing ? { message_id: anchor.message_id, text }
          : { text, ...(route.topic === undefined ? {} : { message_thread_id: route.topic }), ...(route.messageId === undefined ? {} : { reply_parameters: { message_id: route.messageId, allow_sending_without_reply: true } }) };
        this.store.queue(id, row.chat, editing ? 'editMessageText' : 'sendMessage', payload);
        this.store.db.prepare('INSERT INTO reply_parts VALUES(?,?,?)').run(row.id, part, id);
        // Payload identity during v0 recovery is an exact no-op optimization, never a choice of which final to keep.
        if (editing && anchor.text === text) this.store.outgoingStatus(id, 'sent', { ...anchor, chat: { id: Number(row.chat) } });
      }
      this.store.db.prepare('UPDATE reply_turns SET committed=1,outcome=?,final_text=? WHERE id=?').run(outcome, finalText, row.id);
      if (row.ingress_id !== null) this.store.status(row.ingress_id, row.blocked ? 'failed' : outcome);
    });
  }
  fail(context: ReplyContext, notice: string): void {
    if (this.row(context.id)?.committed) return; // A post-commit failure cannot replace or append another reply.
    this.complete(context, notice, 'failed');
  }
}

/** Preserve the entire canonical final, including surrogate pairs; each deterministic part has its own durable ID. */
export function splitReply(text: string): string[] {
  if (!text.trim()) return [];
  const parts: string[] = [];
  for (let start = 0; start < text.length;) {
    let end = Math.min(start + 4000, text.length);
    if (end < text.length && /[\uD800-\uDBFF]/.test(text[end - 1]!) && /[\uDC00-\uDFFF]/.test(text[end]!)) end--;
    parts.push(text.slice(start, end)); start = end;
  }
  return parts;
}
