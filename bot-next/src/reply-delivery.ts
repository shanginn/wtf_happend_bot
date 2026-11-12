import type { Message } from '@earendil-works/pi-ai';

type Call = { method: string; parameters: Record<string, unknown> };
const object = (value: unknown): value is Record<string, unknown> => value !== null && typeof value === 'object' && !Array.isArray(value);
const text = (value: unknown) => typeof value === 'string' ? value.replace(/\r\n?/g, '\n').trim() : '';
const sendsMessage = (method: string) => /^(send|copy|forward)/.test(method) && !/Draft$/.test(method);
const sendsText = (call: Call) => ['sendMessage', 'sendRichMessage'].includes(call.method) && [call.parameters.text, call.parameters.html].some(value => text(value).length > 0);

/** A conversational text send owns this turn's reply, regardless of the final wording.
 * Only the current submission counts. Unknown sends also retain ownership to avoid a second delivery.
 * Media/polls still allow an acknowledgement, except for a caption repeated verbatim.
 */
export function toolOwnsReply(messages: readonly Message[], chat: string, answer: string): boolean {
  const body = text(answer);
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
    const requestedBodyMatches = Boolean(body) && requestedHere && [call.parameters.text, call.parameters.caption, call.parameters.html].some(value => text(value) === body);
    const mayOwnReply = (requestedHere && sendsText(call)) || requestedBodyMatches;
    let definitelyRejected = false;
    for (const item of message.content) if (item.type === 'text') {
      let result: unknown;
      try { result = JSON.parse(item.text); } catch { continue; }
      if (!object(result)) continue;
      if (result.ok === false && result.outcome === 'rejected') definitelyRejected = true;
      if (result.ok === false && result.outcome === 'unknown' && mayOwnReply) return true;
      if (result.ok !== true) continue;
      for (const sent of Array.isArray(result.result) ? result.result : [result.result]) {
        if (!object(sent) || !Number.isSafeInteger(sent.message_id) || !object(sent.chat) || String(sent.chat.id) !== chat) continue;
        if (text(sent.text) || sendsText(call)) return true;
        if (body && (text(sent.caption) === body || requestedBodyMatches)) return true;
      }
    }
    // A host/storage error after dispatch cannot grant a second sender the reply slot.
    if (message.isError && !definitelyRejected && mayOwnReply) return true;
  }
  return false;
}
