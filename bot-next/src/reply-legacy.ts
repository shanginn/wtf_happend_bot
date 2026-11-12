import type { Message } from '@earendil-works/pi-ai';
import type { Replies, ReplyContext } from './replies.js';

const object = (value: unknown): value is Record<string, unknown> => value !== null && typeof value === 'object' && !Array.isArray(value);
/** One-time protocol-v0 recovery only. New turns never infer delivery from transcript text or tool results. */
export function recoverLegacyReply(replies: Replies, context: ReplyContext, chat: string, messages: readonly Message[]): void {
  const calls = new Map<string, Record<string, unknown>>(), anchors: { message_id: number; chat: { id: number }; text?: string }[] = [];
  let uncertain = false;
  for (const message of messages) {
    if (message.role === 'assistant') for (const item of message.content) {
      if (item.type === 'toolCall' && item.name === 'telegram_api' && item.arguments.purpose !== 'action' && ['sendMessage', 'sendRichMessage'].includes(String(item.arguments.method)) && object(item.arguments.parameters)) calls.set(item.id, item.arguments.parameters);
    }
    if (message.role !== 'toolResult' || message.toolName !== 'telegram_api') continue;
    const call = calls.get(message.toolCallId); if (!call) continue;
    let rejected = false, observed = false;
    for (const item of message.content) if (item.type === 'text') {
      let value: unknown; try { value = JSON.parse(item.text); } catch { continue; }
      if (!object(value)) continue;
      if (value.ok === false && value.outcome === 'rejected') rejected = true;
      if (value.ok === false && value.outcome === 'unknown' && String(call.chat_id) === chat) uncertain = true;
      if (value.ok === true && object(value.result) && object(value.result.chat) && String(value.result.chat.id) === chat && Number.isSafeInteger(value.result.message_id)) {
        anchors.push(value.result as unknown as { message_id: number; chat: { id: number }; text?: string }); observed = true;
      }
    }
    if (message.isError && !rejected && !observed && String(call.chat_id) === chat) uncertain = true;
  }
  replies.adoptLegacy(context, anchors, uncertain);
}
