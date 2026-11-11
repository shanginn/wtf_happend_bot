import test from 'node:test';
import assert from 'node:assert/strict';
import { randomBytes } from 'node:crypto';
import { DatabaseSync } from 'node:sqlite';
import { checkWidget, widgetRequestError } from '../src/widget-format.js';
import { Telegram, type TelegramUpdate } from '../src/telegram.js';
import { Store } from '../src/store.js';
import { callTelegramTool } from '../src/telegram-tool.js';
import { messageWidget, sharedWidget, withWidgetState } from '../src/widget-state.js';
import { Widgets } from '../src/widgets.js';
import type { Runtime } from '../src/runtime.js';

const html = '<button onclick="TelegramWidget.request(\'inc\')">+</button><b id="n"></b><script>function render(){document.querySelector("#n").textContent=JSON.parse(TelegramWidget.chatData||"{}").n||0}TelegramWidget.onEvent("chatDataChanged",render);render()</script>';
function fixture() {
  const db = new DatabaseSync(':memory:'), store = new Store(db), calls: { method: string; parameters: Record<string, unknown> }[] = [];
  const telegram = new Telegram('fixture-token', async (url, init) => {
    const method = String(url).split('/').at(-1)!, parameters = JSON.parse(init!.body as string);
    calls.push({ method, parameters });
    if (method === 'sendWidget' || method === 'sendSharedWidget') return Response.json({ ok: true, result: { message_id: calls.length, date: 1794381617, chat: { id: Number(parameters.chat_id), type: 'private' }, from: { id: 777, is_bot: true }, widget: { html: parameters.html ?? html, ...(parameters.is_shared || parameters.widget_id ? { id: parameters.widget_id ?? 'shared-1' } : {}), chat_data: parameters.chat_data, public_data: parameters.public_data } } });
    return Response.json({ ok: true, result: method === 'getUpdates' ? [] : true });
  });
  const widgets = new Widgets(store, telegram, () => 1794381620000);
  const send = (id = 'send:1', chat = '10', extras: Record<string, unknown> = {}) => callTelegramTool(store, telegram, id, chat, 'sendWidget', { chat_id: chat, html, chat_data: '{"n":0}', allow_queries: true, ...extras });
  const query = (id = 50, chat = 10, message = 1, extras: Record<string, unknown> = {}): TelegramUpdate => ({ update_id: id, widget_query: { id: `q:${id}`, from: { id: chat, first_name: 'Fixture User' }, chat: { id: chat, type: 'private' }, message_id: message, data: 'inc', type: { type: 'data' }, ...extras } });
  return { db, store, calls, telegram, widgets, send, query };
}

test('widget HTML limits count UTF-8 and zlib bytes, reject NUL/malformed text, and report unsupported features', () => {
  const checked = checkWidget(html, '{"n":0}');
  assert.equal(checked.ok, true); assert.ok(checked.compressed_bytes! < 4096);
  assert.equal(checkWidget('я'.repeat(16385)).ok, false);
  assert.equal(checkWidget(`<p>${randomBytes(6500).toString('base64')}</p>`).ok, false);
  for (const invalid of ['', 'hello\0world', '\ud800']) assert.equal(checkWidget(invalid).ok, false);
  assert.equal(checkWidget(html, 'я'.repeat(513)).ok, false);
  assert.equal(checkWidget(html, 'я'.repeat(512)).ok, true);
  assert.equal(checkWidget('<input><script>fetch("/api")</script>').warnings.length, 2);
  assert.equal(widgetRequestError('futureBotApiMethod', { html: '\0' }), undefined, 'unrelated/future API methods remain unrestricted');
});

test('invalid widget requests never reach Telegram; confirmed widgets and state edits survive replay', async () => {
  const f = fixture();
  try {
    const rejected = await callTelegramTool(f.store, f.telegram, 'invalid', '10', 'sendWidget', { chat_id: '10', html: '\0' });
    assert.equal(rejected.ok, false); assert.equal(f.calls.length, 0);
    for (const method of ['sendWidget', 'sendSharedWidget']) {
      const group = await callTelegramTool(f.store, f.telegram, `group:${method}`, '-100', method, { chat_id: '-100', html, widget_id: 'shared-1' });
      assert.equal(group.ok, false); if (!group.ok) assert.match(group.description, /only in private chats/);
    }
    assert.equal(f.calls.length, 0, 'known unsupported group deliveries must not reach Telegram');
    const result = await f.send(); assert.equal(result.ok, true);
    assert.deepEqual(await f.send(), result); assert.equal(f.calls.length, 1);
    assert.equal(messageWidget(f.store, '10', 1)?.html, html);
    const args = { chat_id: '10', message_id: 1, chat_data: '{"n":1}' };
    await callTelegramTool(f.store, f.telegram, 'edit:1', '10', 'editMessageWidgetData', args);
    await callTelegramTool(new Store(f.db), f.telegram, 'edit:1', '10', 'editMessageWidgetData', args);
    assert.equal(f.calls.length, 2); assert.equal(messageWidget(f.store, '10', 1)?.chatData, '{"n":1}');
    const original = { message_id: 1, date: 1, chat: { id: 10 }, widget: { html, chat_data: '{"n":0}' } };
    assert.equal(withWidgetState(f.store, original).widget!.chat_data, '{"n":1}');
    assert.equal(withWidgetState(f.store, { message_id: 3, date: 3, chat: { id: 10 }, reply_to_message: original }).reply_to_message!.widget!.chat_data, '{"n":1}');
    assert.equal(original.widget.chat_data, '{"n":0}', 'historical source records remain immutable');
  } finally { f.db.close(); }
});

test('polling subscribes to widget events and query answers use the authenticated Telegram transport', async () => {
  const telegram = new Telegram('fixture', async (url, init) => {
    const parameters = JSON.parse(init!.body as string);
    if (String(url).endsWith('getUpdates')) {
      assert.ok(parameters.allowed_updates.includes('widget_query')); assert.ok(parameters.allowed_updates.includes('shared_widget_query'));
      return Response.json({ ok: true, result: [] });
    }
    assert.ok(init?.signal); return Response.json({ ok: true, result: true });
  });
  await telegram.updates(42); await telegram.call('answerWidgetQuery', { widget_query_id: 'query' });
});

test('queries are acknowledged before model work; duplicate deliveries do not repeat ACK or widget action', async () => {
  const f = fixture();
  try {
    await f.send(); await f.widgets.accept(f.query());
    assert.equal(f.calls[1]!.method, 'answerWidgetQuery');
    assert.equal(JSON.parse(f.calls[1]!.parameters.data as string).status, 'accepted');
    assert.equal(f.widgets.pending().length, 1);
    let modelCalls = 0;
    const runtime: Pick<Runtime, 'respond'> = { respond: async (chat, id, content, _text, entry, session) => {
      modelCalls++; assert.equal(chat, '10'); assert.equal(id, 'widget:q:50'); assert.equal(session, 'widget:message:10:1');
      assert.ok(JSON.stringify(entry!.data).includes('"telegramParticipant":"telegram_user:10"'));
      assert.ok(String(content).includes('2026-11-11 12:20:20 +05:00')); assert.ok(String(content).includes('"chat_data":"{\\"n\\":0}"'));
      await callTelegramTool(f.store, f.telegram, 'click-edit:50', chat, 'editMessageWidgetData', { chat_id: chat, message_id: 1, chat_data: '{"n":1}' });
      return 'Internal completion, never posted to chat';
    } };
    await f.widgets.process(f.widgets.pending()[0]!, runtime, 'Asia/Yekaterinburg');
    await f.widgets.accept(f.query());
    assert.equal(modelCalls, 1); assert.equal(f.widgets.pending().length, 0); assert.equal(f.calls.length, 3);
    assert.equal(messageWidget(f.store, '10', 1)?.chatData, '{"n":1}');
    assert.equal(f.store.outgoing().length, 0);
  } finally { f.db.close(); }
});

test('unknown, cross-chat, disabled, malformed and wallet queries receive an error without entering the model queue', async () => {
  const f = fixture();
  try {
    await f.send();
    await f.widgets.accept(f.query(50, 20));
    await f.widgets.accept(f.query(51), ['20']);
    await f.widgets.accept(f.query(52, 10, 1, { data: 'я'.repeat(33) }));
    await f.widgets.accept(f.query(53, 10, 1, { type: { type: 'sent_transaction', wallet: { address: 'not-proof-of-payment' } } }));
    f.store.set('paused:10', 'yes'); await f.widgets.accept(f.query(54));
    assert.equal(f.widgets.pending().length, 0);
    assert.deepEqual(f.calls.slice(1).map(call => JSON.parse(call.parameters.data as string).error), ['unknown_widget', 'chat_not_enabled', 'invalid_action', 'wallet_queries_not_supported', 'bot_paused']);
  } finally { f.db.close(); }
});

test('shared widgets keep public state current without exposing the original message chat data to forwarded queries', async () => {
  const f = fixture();
  try {
    await f.send('shared', '10', { is_shared: true, public_data: '{"votes":0}', chat_data: 'PRIVATE_MESSAGE_DATA' });
    await callTelegramTool(f.store, f.telegram, 'share', '20', 'sendSharedWidget', { chat_id: '20', widget_id: 'shared-1' });
    assert.equal(sharedWidget(f.store, 'shared-1')?.chat, '10', 'sharing must not replace the original owner');
    assert.equal(messageWidget(f.store, '20', 2)?.chatData, '', 'another message must not inherit the original private chat state');
    await callTelegramTool(f.store, f.telegram, 'public-edit', '10', 'editSharedWidgetData', { widget_id: 'shared-1', public_data: '{"votes":1}' });
    assert.equal(messageWidget(f.store, '10', 1)?.publicData, '{"votes":1}'); assert.equal(sharedWidget(f.store, 'shared-1')?.publicData, '{"votes":1}');
    const update = { update_id: 55, shared_widget_query: { id: 'q:shared', from: { id: 30 }, widget_id: 'shared-1', data: 'vote', type: { type: 'data' } } };
    await f.widgets.accept(update);
    const runtime: Pick<Runtime, 'respond'> = { respond: async (_chat, _id, content, _text, _entry, session) => {
      assert.equal(session, 'widget:shared:shared-1:public'); assert.ok(!String(content).includes('PRIVATE_MESSAGE_DATA')); assert.ok(!String(content).includes('"chat_data"'));
      return 'done';
    } };
    await f.widgets.process(f.widgets.pending()[0]!, runtime, 'UTC');
    assert.equal(f.widgets.pending().length, 0);
  } finally { f.db.close(); }
});
