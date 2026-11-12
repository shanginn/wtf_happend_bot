import type { Message } from '@earendil-works/pi-ai';

type Call = { method: string; parameters: Record<string, unknown> };
const object = (value: unknown): value is Record<string, unknown> => value !== null && typeof value === 'object' && !Array.isArray(value);
const text = (value: unknown) => typeof value === 'string' ? value.replace(/\r\n?/g, '\n').trim() : '';
const sendsMessage = (method: string) => /^(send|copy|forward)/.test(method) && !/Draft$/.test(method);

/** The final answer is auto-delivered by main. A tool may already have delivered the same body.
 * Only evidence from this submission is accepted; an uncertain send must not be retried via another path.
 */
export function replyAlreadyDelivered(messages: readonly Message[], chat: string, answer: string): boolean {
  const body = text(answer);
  if (!body) return false;
  const calls = new Map<string, Call>();
  for (const message of messages) {
    if (message.role === 'assistant') for (const item of message.content) {
      if (item.type === 'toolCall' && item.name === 'telegram_api' && typeof item.arguments.method === 'string' && object(item.arguments.parameters)) {
        calls.set(item.id, { method: item.arguments.method, parameters: item.arguments.parameters });
      }
    }
    if (message.role !== 'toolResult' || message.toolName !== 'telegram_api') continue;
    const call = calls.get(message.toolCallId);
    if (!call || !sendsMessage(call.method)) continue;
    const requestedHere = String(call.parameters.chat_id) === chat;
    const requestedBodyMatches = requestedHere && [call.parameters.text, call.parameters.caption, call.parameters.html].some(value => text(value) === body);
    // A host/storage error after sending is also an unconfirmed delivery, not permission to send again.
    if (message.isError && requestedBodyMatches) return true;
    for (const item of message.content) if (item.type === 'text') {
      let result: unknown;
      try { result = JSON.parse(item.text); } catch { continue; }
      if (!object(result)) continue;
      if (result.ok === false && result.outcome === 'unknown' && requestedBodyMatches) return true;
      if (result.ok !== true) continue;
      for (const sent of Array.isArray(result.result) ? result.result : [result.result]) {
        if (!object(sent) || !Number.isSafeInteger(sent.message_id) || !object(sent.chat) || String(sent.chat.id) !== chat) continue;
        if ([sent.text, sent.caption].some(value => text(value) === body) || requestedBodyMatches) return true;
      }
    }
  }
  return false;
}
