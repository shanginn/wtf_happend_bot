import { LiveDoc, type ToolExecutionApi } from '@earendil-works/pi-durable';
import type { Context } from '@earendil-works/chord';
import { Replies, ReplyProtocolError, replyId, type ReplyContext } from './replies.js';

export const replyProtocolField = 'replyProtocol';
export function replyContext(value: unknown): ReplyContext | undefined {
  if (!value || typeof value !== 'object' || Array.isArray(value)) return undefined;
  const record = value as Record<string, unknown>, candidate = record[replyProtocolField];
  if (!candidate || typeof candidate !== 'object' || Array.isArray(candidate)) return undefined;
  const data = candidate as Record<string, unknown>;
  return data.version === 1 && typeof data.id === 'string' ? { version: 1, id: data.id } : undefined;
}
/** Correlate through Pi's durable run/input identity, never through text or the latest mutable chat route. */
export async function toolReplyContext(api: ToolExecutionApi, context: Context, replies: Replies, chat: string, scope: string): Promise<{ delivery: ReplyContext; legacy: boolean }> {
  const saved = await api.memo('reply-context:v1', context);
  if (saved) {
    const parsed = replyContext({ replyProtocol: saved });
    if (parsed && typeof saved === 'object' && !Array.isArray(saved)) return { delivery: parsed, legacy: Boolean(saved.legacy) };
    throw new ReplyProtocolError('REPLY_CONTEXT_INVALID');
  }
  const live = await api.snapshot(LiveDoc, api.conversationId, context);
  if (!live?.run?.first || live.run.inputs.length !== 1) throw new ReplyProtocolError('REPLY_CONTEXT_REQUIRES_ONE_ADMITTED_INPUT');
  const input = await api.commit(tx => tx.entry(live.run!.first!), context);
  let delivery = replyContext(input?.data), legacy = false;
  // Versioned upgrade adapter for already-admitted Telegram inputs. Identity comes from host metadata, not text.
  if (!delivery && input?.kind === 'telegram.message' && input.data && typeof input.data === 'object' && !Array.isArray(input.data) && typeof input.data.telegramUpdateId === 'number') {
    const id = replyId(scope, `telegram:${input.data.telegramUpdateId}`), row = replies.row(id);
    if (row?.chat === chat) { delivery = { version: 1, id }; legacy = true; }
  }
  if (!delivery) throw new ReplyProtocolError('REPLY_CONTEXT_MISSING_FINISH_WITH_FINAL_TEXT');
  await api.memo('reply-context:v1', { version: delivery.version, id: delivery.id, legacy }, context);
  return { delivery, legacy };
}
