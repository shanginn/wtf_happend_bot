import { createHash } from 'node:crypto';
import { defineTool } from '@earendil-works/pi-durable';
import { arr, map, obj, opt, oneOf, str, unknown } from '@earendil-works/pi-schema';
import type { Store } from './store.js';
import { Telegram, TelegramError, type TelegramFile } from './telegram.js';

type Result = { ok: true; result: unknown } | { ok: false; outcome: 'rejected' | 'unknown'; description: string; error_code?: number; parameters?: Record<string, unknown> };
const uncertain: Result = { ok: false, outcome: 'unknown', description: 'Telegram may have performed this request. Do not repeat it: delivery could not be confirmed.' };

/** Persist before making the request: a Pi replay must never send the same poll/payment/message twice. */
export async function callTelegramTool(store: Store, telegram: Telegram, id: string, chat: string, method: string, parameters: Record<string, unknown>, files: TelegramFile[] = []): Promise<Result> {
  const hash = createHash('sha256').update(JSON.stringify({ method, parameters, files })).digest('hex');
  const previous = store.db.prepare('SELECT request_hash,status,result FROM telegram_calls WHERE id=?').get(id) as { request_hash: string; status: string; result: string | null } | undefined;
  if (previous) {
    if (previous.request_hash !== hash) throw new Error('Telegram operation ID reused with different arguments');
    return previous.result ? JSON.parse(previous.result) as Result : uncertain;
  }
  store.db.prepare("INSERT INTO telegram_calls(id,chat,request_hash,status) VALUES(?,?,?,'running')").run(id, chat, hash);
  let result: Result;
  try { result = { ok: true, result: await telegram.call(method, parameters, files) }; }
  catch (error) {
    result = error instanceof TelegramError
      ? { ok: false, outcome: error.outcome, description: error.message, ...(error.code === undefined ? {} : { error_code: error.code }), ...(error.parameters ? { parameters: error.parameters } : {}) }
      : uncertain;
  }
  store.db.prepare('UPDATE telegram_calls SET status=?,result=? WHERE id=?').run(result.ok ? 'done' : result.outcome, JSON.stringify(result), id);
  if (result.ok && /^(send|copy|forward)/.test(method)) store.set(`spoke:${chat}`, String(Date.now()));
  return result;
}

export function telegramTool(telegram: Telegram, store: Store, chat: string) {
  return defineTool({
    name: 'telegram_api',
    description: `Call ANY Telegram Bot API method, with its official method name and arbitrary parameters. No method allowlist. Current chat_id is ${chat}; include it in parameters for chat methods. For example sendPoll: {chat_id:"${chat}",question:"Question?",options:[{text:"Yes"},{text:"No"}]}. Send, edit or delete messages; polls, reactions, pins, moderation, invites, files, stickers, Stars and all other Bot API methods use this same tool. Existing file_id values and HTTPS file URLs go directly in parameters. To upload bytes, use files (base64 by default, or utf8); put attach://FIELD in parameters for nested attachments such as sendMediaGroup. Returns the actual Telegram result or error, including permission errors. On unknown outcome do not repeat the action.`,
    parameters: obj({ method: str(), parameters: map(unknown()), files: opt(arr(obj({ field: str(), data: str(), filename: str(), mime_type: opt(str()), encoding: opt(oneOf(['base64', 'utf8'])) }))) }),
    replay: 'safe',
    execute: async ({ method, parameters, files }, api) => ({ output: [{ type: 'text' as const, text: JSON.stringify(await callTelegramTool(store, telegram, `telegram-api:${chat}:${api.taskId}`, chat, method, parameters, files)) }] }),
  });
}
