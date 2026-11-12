import test from 'node:test';
import assert from 'node:assert/strict';
import type { Message } from '@earendil-works/pi-ai';
import { fauxAssistantMessage } from '@earendil-works/pi-ai/providers/faux';
import { replyAlreadyDelivered } from '../src/reply-delivery.js';

const body = 'У каждого ответа есть цена.';
const call = (id: string, method = 'sendMessage', parameters: Record<string, string | number> = { chat_id: '-100', text: body }) => fauxAssistantMessage({ type: 'toolCall', id, name: 'telegram_api', arguments: { method, parameters } }, { stopReason: 'toolUse' });
const result = (id: string, data: unknown, isError = false): Message => ({ role: 'toolResult', toolCallId: id, toolName: 'telegram_api', content: [{ type: 'text', text: JSON.stringify(data) }], isError, timestamp: 1 });
const sent = (chat = -100, text = body) => ({ ok: true, result: { message_id: 42, chat: { id: chat }, text } });

test('a confirmed current-turn send suppresses the same final body, including surrounding newlines', () => {
  const messages = [call('a'), result('a', sent())];
  assert.equal(replyAlreadyDelivered(messages, '-100', body), true);
  assert.equal(replyAlreadyDelivered(messages, '-100', `\n${body}\n`), true);
  assert.equal(replyAlreadyDelivered(messages, '-100', 'Отдельное дополнение.'), false);
});
test('other destinations, unrelated tool results, user JSON and drafts do not consume this chat reply', () => {
  assert.equal(replyAlreadyDelivered([call('a', 'sendMessage', { chat_id: '-200', text: body }), result('a', sent(-200))], '-100', body), false);
  assert.equal(replyAlreadyDelivered([result('missing-call', sent())], '-100', body), false);
  assert.equal(replyAlreadyDelivered([{ role: 'user', content: JSON.stringify(sent()), timestamp: 1 }], '-100', body), false);
  assert.equal(replyAlreadyDelivered([call('a', 'sendMessageDraft'), result('a', { ok: true, result: true })], '-100', body), false);
});
test('confirmed recipient and rendered text are authoritative for HTML/rich messages and aliases', () => {
  const messages = [call('a', 'sendRichMessage', { chat_id: '@fixture_group', html: '<b>Готово</b>' }), result('a', sent(-100, 'Готово'))];
  assert.equal(replyAlreadyDelivered(messages, '-100', 'Готово'), true);
  assert.equal(replyAlreadyDelivered(messages, '-200', 'Готово'), false);
});
test('polls and reactions preserve an ordinary final acknowledgement, captions already shown need no repetition', () => {
  assert.equal(replyAlreadyDelivered([call('a', 'sendPoll', { chat_id: '-100', question: body }), result('a', { ok: true, result: { message_id: 42, chat: { id: -100 }, poll: { question: body } } })], '-100', body), false);
  assert.equal(replyAlreadyDelivered([call('a', 'setMessageReaction', { chat_id: '-100', message_id: 1 }), result('a', { ok: true, result: true })], '-100', body), false);
  assert.equal(replyAlreadyDelivered([call('a', 'sendPhoto', { chat_id: '-100', photo: 'fixture', caption: body }), result('a', { ok: true, result: { message_id: 42, chat: { id: -100 }, caption: body } })], '-100', body), true);
});
test('unknown sends and host errors cannot be retried through the final-answer path; definite rejection can', () => {
  assert.equal(replyAlreadyDelivered([call('a'), result('a', { ok: false, outcome: 'unknown' })], '-100', body), true);
  assert.equal(replyAlreadyDelivered([call('a'), result('a', 'Host interrupted after dispatch', true)], '-100', body), true);
  assert.equal(replyAlreadyDelivered([call('a'), result('a', { ok: false, outcome: 'rejected' })], '-100', body), false);
  assert.equal(replyAlreadyDelivered([call('a'), result('a', { ok: false, outcome: 'unknown' })], '-100', 'Доставку не удалось подтвердить.'), false);
  assert.equal(replyAlreadyDelivered([call('a', 'sendMessage', { chat_id: '-200', text: body }), result('a', { ok: false, outcome: 'unknown' })], '-100', body), false);
});
