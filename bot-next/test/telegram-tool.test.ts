import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { DatabaseSync } from 'node:sqlite';
import { Telegram, TelegramError } from '../src/telegram.js';
import { callTelegramTool } from '../src/telegram-tool.js';
import { Store } from '../src/store.js';

test('any method and arbitrary nested parameters pass through without a method allowlist', async () => {
  const calls: { url: string; body: unknown }[] = [];
  const telegram = new Telegram('private-token', async (url, init) => {
    calls.push({ url: String(url), body: JSON.parse(init!.body as string) });
    return Response.json({ ok: true, result: { future_api_field: [1, true, null] } });
  });
  const parameters = { business_connection_id: 'business', custom: { values: [1, true, null] }, chat_id: '@chosen_chat' };
  assert.deepEqual(await telegram.call('newFutureBotApiMethod', parameters), { future_api_field: [1, true, null] });
  assert.deepEqual(calls, [{ url: 'https://api.telegram.org/botprivate-token/newFutureBotApiMethod', body: parameters }]);
  await assert.rejects(telegram.call('../not-a-method'), /Invalid Bot API method/);
  assert.equal(calls.length, 1);
});

test('multipart supports both direct file fields and nested attach references without changing parameters', async () => {
  const telegram = new Telegram('private-token', async (_url, init) => {
    assert.equal(init?.headers, undefined, 'fetch must generate the multipart boundary');
    assert.ok(init?.body instanceof FormData);
    const form = init.body;
    assert.equal(form.get('chat_id'), '-100');
    assert.equal(form.get('disable_notification'), 'true');
    assert.deepEqual(JSON.parse(form.get('media') as string), [{ type: 'document', media: 'attach://report' }, { type: 'photo', media: 'attach://picture' }]);
    const report = form.get('report') as File, picture = form.get('picture') as File;
    assert.equal(report.name, 'report.txt');
    assert.equal(report.type, 'text/plain');
    assert.equal(await report.text(), 'Привет, чат!');
    assert.deepEqual(Buffer.from(await picture.arrayBuffer()), Buffer.from([0, 1, 128, 255]));
    return Response.json({ ok: true, result: [{ message_id: 42 }] });
  });
  assert.deepEqual(await telegram.call('sendMediaGroup', { chat_id: '-100', disable_notification: true, media: [{ type: 'document', media: 'attach://report' }, { type: 'photo', media: 'attach://picture' }] }, [
    { field: 'report', filename: 'report.txt', mime_type: 'text/plain', data: 'Привет, чат!', encoding: 'utf8' },
    { field: 'picture', filename: 'picture.png', mime_type: 'image/png', data: Buffer.from([0, 1, 128, 255]).toString('base64') },
  ]), [{ message_id: 42 }]);
});

test('Telegram reports actual permission and rate limit errors without exposing the token', async () => {
  const telegram = new Telegram('private-token', async () => Response.json({ ok: false, error_code: 429, description: 'Too Many Requests: private-token', parameters: { retry_after: 7 } }, { status: 429 }));
  await assert.rejects(telegram.call('sendPoll', {}), error => error instanceof TelegramError && error.outcome === 'rejected' && error.code === 429 && error.parameters?.retry_after === 7 && error.message.includes('Too Many Requests') && !error.message.includes('private-token'));
});

test('confirmed polls survive replay and enter the originating chat context only', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db); let requests = 0;
    const poll = { message_id: 2, date: 200, from: { id: 777, is_bot: true }, chat: { id: -100 }, poll: { id: 'poll-42', question: 'Восстанавливать память?', options: [{ text: 'Да', voter_count: 0 }, { text: 'Нет', voter_count: 0 }], total_voter_count: 0, is_closed: false } };
    const telegram = new Telegram('private-token', async () => { requests++; return Response.json({ ok: true, result: poll }); });
    const parameters = { chat_id: '-100', question: poll.poll.question, options: [{ text: 'Да' }, { text: 'Нет' }] };
    const result = await callTelegramTool(store, telegram, 'poll:1', '-100', 'sendPoll', parameters);
    assert.deepEqual(result, { ok: true, result: poll });
    const restarted = new Store(db); restarted.recoverInterrupted();
    assert.deepEqual(await callTelegramTool(restarted, telegram, 'poll:1', '-100', 'sendPoll', parameters), result);
    assert.equal(requests, 1);
    await assert.rejects(callTelegramTool(restarted, telegram, 'poll:1', '-100', 'sendPoll', { ...parameters, question: 'Changed' }), /different arguments/);
    for (const chat of [-100, -200]) store.ingest({ update_id: -chat, message: { message_id: 3, date: 300, chat: { id: chat }, text: 'Какой опрос?' } });
    assert.equal(store.conversation('-100', 100)[0]?.poll?.id, 'poll-42');
    assert.equal(store.conversation('-200', 200).some(m => m.poll), false);
  } finally { db.close(); }
});

test('network uncertainty and interrupted calls are never resubmitted during replay', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db); let requests = 0;
    const telegram = new Telegram('private-token', async () => { requests++; throw new Error('Connection reset'); });
    const parameters = { chat_id: '-100', question: 'Question', options: [{ text: 'Yes' }, { text: 'No' }] };
    const result = await callTelegramTool(store, telegram, 'network', '-100', 'sendPoll', parameters);
    assert.equal(result.ok, false);
    if (!result.ok) assert.equal(result.outcome, 'unknown');
    assert.deepEqual(await callTelegramTool(store, telegram, 'network', '-100', 'sendPoll', parameters), result);
    assert.equal(requests, 1);
    const hash = createHash('sha256').update(JSON.stringify({ method: 'sendPoll', parameters, files: [] })).digest('hex');
    db.prepare("INSERT INTO telegram_calls(id,chat,request_hash,status) VALUES('crashed','-100',?,'running')").run(hash);
    const restarted = new Store(db); restarted.recoverInterrupted();
    const recovered = await callTelegramTool(restarted, telegram, 'crashed', '-100', 'sendPoll', parameters);
    assert.equal(recovered.ok, false);
    if (!recovered.ok) assert.equal(recovered.outcome, 'unknown');
    assert.equal(requests, 1);
    assert.equal(db.prepare("SELECT status FROM telegram_calls WHERE id='crashed'").get()!.status, 'unknown');
  } finally { db.close(); }
});
