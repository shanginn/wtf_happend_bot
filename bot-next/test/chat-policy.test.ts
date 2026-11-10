import test from 'node:test';
import assert from 'node:assert/strict';
import { canManageChat } from '../src/chat-policy.js';
import type { TelegramMessage, Telegram } from '../src/telegram.js';

const message: TelegramMessage = { message_id: 1, date: 1, chat: { id: -100, type: 'supergroup' }, from: { id: 7 } };
test('automatic chats grant management only to that chat administrator', async () => {
  const calls: unknown[] = [];
  const telegram: Pick<Telegram, 'call'> = { async call<T>(method: string, body?: Record<string, unknown>) {
    calls.push({ method, body }); return { status: body?.chat_id === -100 ? 'administrator' : 'member' } as T;
  } };
  assert.equal(await canManageChat(message, [], telegram), true);
  assert.equal(await canManageChat({ ...message, chat: { id: -200, type: 'supergroup' } }, [], telegram), false);
  assert.deepEqual(calls, [{ method: 'getChatMember', body: { chat_id: -100, user_id: 7 } }, { method: 'getChatMember', body: { chat_id: -200, user_id: 7 } }]);
});
test('private chat management is scoped to its participant; failed membership checks deny', async () => {
  const failed: Pick<Telegram, 'call'> = { async call() { throw new Error('Unavailable'); } };
  assert.equal(await canManageChat({ ...message, chat: { id: 7, type: 'private' } }, [], failed), true);
  assert.equal(await canManageChat({ ...message, chat: { id: 8, type: 'private' } }, [], failed), false);
  assert.equal(await canManageChat(message, [], failed), false);
  assert.equal(await canManageChat(message, ['7'], failed), true);
});
