import { defineTool } from '@earendil-works/pi-durable';
import { num, obj, opt, str } from '@earendil-works/pi-schema';
import { normalizedText, type MemoryStore } from './memory.js';
import type { Store } from './store.js';
import type { Replies } from './replies.js';
import type { TelegramMessage, TelegramUpdate } from './telegram.js';
import { formatTelegramEvent, type HistoryCoverage } from './telegram-context.js';
import { toolReplyContext } from './reply-context.js';

export function historyCoverage(store: Store, chat: string, asOfUpdate: number): HistoryCoverage {
  const floor = Number(store.get(`memory:context-floor:${chat}`) ?? 0);
  const row = store.db.prepare(`WITH messages AS (
    SELECT COALESCE(json_extract(payload,'$.message'),json_extract(payload,'$.edited_message')) AS message
    FROM ingress WHERE chat=? AND id<=?
  ) SELECT count(DISTINCT json_extract(message,'$.message_id')) AS count,
    min(json_extract(message,'$.date')) AS first,max(json_extract(message,'$.date')) AS last
    FROM messages WHERE json_extract(message,'$.message_id')>?
    AND json_extract(message,'$.successful_payment') IS NULL AND json_extract(message,'$.refunded_payment') IS NULL`).get(chat, asOfUpdate, floor) as { count: number; first: number | null; last: number | null };
  return { storedMessages: row.count, oldestMessageAt: row.first === null ? null : new Date(row.first * 1000).toISOString(), newestMessageAt: row.last === null ? null : new Date(row.last * 1000).toISOString() };
}

export function searchChatHistory(store: Store, memory: MemoryStore, chat: string, asOfUpdate: number, query: string, limit = 8, timezone = 'Asia/Yekaterinburg', beforeUpdate = asOfUpdate) {
  if (typeof query !== 'string' || !query.trim() || query.length > 500 || !Number.isSafeInteger(asOfUpdate) || asOfUpdate < 1 || !Number.isSafeInteger(beforeUpdate) || beforeUpdate < 1 || beforeUpdate > asOfUpdate || !Number.isSafeInteger(limit) || limit < 1 || limit > 20) throw new Error('Invalid history search parameters');
  const terms = normalizedText(query).split(/\s+/u).filter(Boolean);
  const floor = Number(store.get(`memory:context-floor:${chat}`) ?? 0);
  const visible = (message: TelegramMessage) => String(message.chat.id) === chat && message.message_id > floor && !message.successful_payment && !message.refunded_payment && !memory.isSourceForgotten(chat, `${chat}:${message.message_id}`);
  const matches: { update_id: number; message_id: number; message: string; reply?: string }[] = [];
  let more = false;
  // Select each message's latest version at the admitted input before applying the page cursor.
  // Otherwise pagination could revive a superseded edit from an earlier page.
  const rows = store.db.prepare(`WITH versions AS (
    SELECT id,payload,row_number() OVER (
      PARTITION BY COALESCE(json_extract(payload,'$.message.message_id'),json_extract(payload,'$.edited_message.message_id'))
      ORDER BY id DESC) AS version FROM ingress WHERE chat=? AND id<?
    ) SELECT id,payload FROM versions WHERE version=1 AND id<? ORDER BY id DESC`).iterate(chat, asOfUpdate, beforeUpdate) as Iterable<{ id: number; payload: string }>;
  for (const row of rows) {
    const update = JSON.parse(row.payload) as TelegramUpdate, message = update.message ?? update.edited_message;
    if (!message) continue;
    if (!visible(message)) continue;
    const text = normalizedText(message.text ?? message.caption ?? '');
    if (!terms.every(term => text.includes(term))) continue;
    if (matches.length === limit) { more = true; break; }
    const reply = message.reply_to_message;
    const options = { timezone, bot: { id: 0 }, canShowSource: visible };
    matches.push({ update_id: row.id, message_id: message.message_id, message: formatTelegramEvent({ message, updateId: row.id, kind: update.edited_message ? 'edited message' : 'message' }, options),
      ...(reply && visible(reply) ? { reply: formatTelegramEvent({ message: reply, kind: 'message' }, options) } : {}) });
  }
  return { query, as_of_update_id: asOfUpdate, before_update_id: beforeUpdate, matches, next_before_update_id: more ? matches.at(-1)!.update_id : null };
}

export function chatHistoryTool(store: Store, memory: MemoryStore, replies: Replies, chat: string, scope: string, timezone: string) {
  return defineTool({
    name: 'search_chat_history',
    description: 'Search saved incoming Telegram messages and their quoted replies in this chat, including earlier days. Query is a case-insensitive substring or short keywords; all keywords must match. Returns original authors, dates, message/update IDs and text. This archive is separate from saved knowledge facts and the recent context window. before_update_id is an exclusive pagination cursor. Current/future input, forgotten sources, payments and other chats are excluded.',
    parameters: obj({ query: str(), limit: opt(num()), before_update_id: opt(num()) }), replay: 'safe',
    execute: async ({ query, limit, before_update_id }, api, context) => {
      const binding = await toolReplyContext(api, context, replies, chat, scope), turn = replies.row(binding.delivery.id);
      if (turn?.ingress_id === null || turn?.ingress_id === undefined) throw new Error('Chat history requires an admitted Telegram input');
      if (before_update_id !== undefined && before_update_id > turn.ingress_id) throw new Error('History cursor exceeds the current input');
      const result = searchChatHistory(store, memory, chat, turn.ingress_id, query, limit, timezone, before_update_id ?? turn.ingress_id);
      return { output: [{ type: 'text' as const, text: JSON.stringify({ ...result, archive: historyCoverage(store, chat, turn.ingress_id) }) }] };
    },
  });
}
