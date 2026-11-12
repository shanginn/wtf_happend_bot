import { createHash } from 'node:crypto';
import { defineTool } from '@earendil-works/pi-durable';
import { arr, map, obj, opt, oneOf, str, unknown } from '@earendil-works/pi-schema';
import type { Store } from './store.js';
import { Telegram, TelegramError, type TelegramFile } from './telegram.js';
import { atomic } from './budget.js';
import { widgetRequestError } from './widget-format.js';
import { rememberWidgetCall } from './widget-state.js';
import { Replies, ReplyProtocolError } from './replies.js';
import { toolReplyContext } from './reply-context.js';

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
  const validation = widgetRequestError(method, parameters);
  if (validation) return { ok: false, outcome: 'rejected', description: validation };
  store.db.prepare("INSERT INTO telegram_calls(id,chat,request_hash,status) VALUES(?,?,?,'running')").run(id, chat, hash);
  let result: Result;
  try { result = { ok: true, result: await telegram.call(method, parameters, files) }; }
  catch (error) {
    result = error instanceof TelegramError
      ? { ok: false, outcome: error.outcome, description: error.message, ...(error.code === undefined ? {} : { error_code: error.code }), ...(error.parameters ? { parameters: error.parameters } : {}) }
      : uncertain;
  }
  atomic(store.db, () => {
    store.db.prepare('UPDATE telegram_calls SET status=?,result=? WHERE id=?').run(result.ok ? 'done' : result.outcome, JSON.stringify(result), id);
    if (result.ok) rememberWidgetCall(store, method, parameters, result.result);
  });
  if (result.ok && /^(send|copy|forward)/.test(method)) store.set(`spoke:${chat}`, String(Date.now()));
  return result;
}

export function telegramTool(telegram: Telegram, store: Store, chat: string, operationNamespace = chat, replies = new Replies(store), replyScope = chat) {
  return defineTool({
    name: 'telegram_api',
    description: `Full Telegram Bot API, no method allowlist. Current chat_id is ${chat}. DELIVERY CONTRACT: sendMessage/sendRichMessage default to purpose=reply. A conversational reply is a draft, NOT an HTTP send: only plain sendMessage with the current chat/source/topic can be staged; it returns delivery=draft,sent=false and no message_id. Your complete final answer replaces draft text and is delivered once by the application. Do not claim a draft was sent. For a separately intended literal API action (another recipient, multiple independent messages, exact formatting/entities/keyboards) set purpose=action explicitly; then ANY method/parameters are forwarded unchanged and the actual Telegram result is returned. Never use action merely to deliver an ordinary conversational reply. Non-text methods default to action.
Direct api.telegram.org uses the latest server API (verified 10.4, 2026-11-09), with no SDK version gate. Example sendPoll: {chat_id:"${chat}",question:"Question?",options:[{text:"Yes"},{text:"No"}]}. HTML widgets: sendWidget {chat_id,html,caption,chat_data,public_data,allow_queries}; check HTML with telegram_widget_check first. Production widgets are private-chat only. sendSharedWidget reuses widget_id; editMessageWidgetData/editSharedWidgetData change state; HTML cannot be replaced. Polls, reactions, moderation, files, stickers, Stars and all other methods remain available. File_id/HTTPS URLs go in parameters. For uploads use files (base64 or utf8); nested attachments use attach://FIELD. Literal actions return actual permission errors. Never retry an unknown action outcome.`,
    parameters: obj({ method: str(), parameters: map(unknown()), purpose: opt(oneOf(['reply', 'action'])), files: opt(arr(obj({ field: str(), data: str(), filename: str(), mime_type: opt(str()), encoding: opt(oneOf(['base64', 'utf8'])) }))) }),
    replay: 'safe',
    execute: async ({ method, parameters, files, purpose }, api, context) => {
      const operation = `telegram-api:${operationNamespace}:${api.taskId}`;
      const intent = purpose ?? (method === 'sendMessage' || method === 'sendRichMessage' ? 'reply' : 'action');
      let result: unknown;
      if (intent === 'action') {
        if (store.db.prepare('SELECT 1 FROM reply_drafts WHERE id=?').get(operation)) throw new ReplyProtocolError('TELEGRAM_OPERATION_INTENT_CONFLICT');
        result = await callTelegramTool(store, telegram, operation, chat, method, parameters, files);
      }
      else try {
        if (files?.length) throw new ReplyProtocolError('REPLY_DRAFT_FILES_REQUIRE_ACTION');
        const binding = await toolReplyContext(api, context, replies, chat, replyScope);
        const previous = store.db.prepare('SELECT result,status FROM telegram_calls WHERE id=?').get(operation) as { result: string | null; status: string } | undefined;
        if (previous) {
          if (!binding.legacy) throw new ReplyProtocolError('TELEGRAM_OPERATION_INTENT_CONFLICT');
          const value = previous.result ? JSON.parse(previous.result) as { ok?: boolean; result?: { message_id: number; chat: { id: number }; text?: string }; outcome?: string } : undefined;
          replies.adoptLegacy(binding.delivery, value?.ok && value.result ? [value.result] : [], !value || value.outcome === 'unknown');
        }
        result = { ...replies.draft(binding.delivery, operation, chat, method, parameters), description: 'Draft only; no Telegram request was made and no message_id exists. Return the complete final answer. The final replaces the draft and is delivered once by the application.' };
      } catch (error) {
        if (!(error instanceof ReplyProtocolError)) throw error;
        result = { ok: false, outcome: 'rejected', protocol_error: error.code, description: 'No Telegram request was made. For a conversational reply return complete final text. Use purpose=action only for a separately intended literal Telegram API action, never to bypass the reply channel.' };
      }
      return { output: [{ type: 'text' as const, text: JSON.stringify(result) }] };
    },
  });
}
