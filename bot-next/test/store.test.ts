import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { Store } from '../src/store.js';
import type { TelegramUpdate } from '../src/telegram.js';

const update = (id: number, chat = -100, text = 'hello'): TelegramUpdate => ({ update_id: id, message: { message_id: id, date: 1, chat: { id: chat }, from: { id: 42 }, text } });

test('new chats are admitted automatically as separate entities; join/leave events require no model call', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db);
    store.ingest({ update_id: 1, my_chat_member: { chat: { id: -100, type: 'supergroup', title: 'First group' }, new_chat_member: { status: 'member' } } });
    assert.equal(store.pending().length, 0);
    assert.equal(store.ingest(update(2, -100, 'first group fact')), true);
    assert.equal(store.ingest(update(3, -200, 'other group fact')), true);
    assert.deepEqual(store.recent('-100').map(m => m.text), ['first group fact']);
    assert.deepEqual(store.recent('-200').map(m => m.text), ['other group fact']);
    store.ingest({ update_id: 4, my_chat_member: { chat: { id: -100, type: 'supergroup' }, new_chat_member: { status: 'left' } } });
    const entities = db.prepare('SELECT id,title,active FROM chat_entities ORDER BY id').all();
    assert.deepEqual(entities.map(e => ({ ...e })), [{ id: '-100', title: 'First group', active: 0 }, { id: '-200', title: null, active: 1 }]);
    store.ingest(update(5, -300), []);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM chat_entities').get()!.n, 2);
    assert.equal(store.get('offset'), '6');
  } finally { db.close(); }
});

test('duplicate updates are stored once; cursor advances for excluded chats without storing their messages', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db);
    assert.equal(store.ingest(update(1), ['-100']), true);
    assert.equal(store.ingest(update(1), ['-100']), false);
    assert.equal(store.ingest(update(3, -200, 'private elsewhere'), ['-100']), false);
    assert.equal(store.get('offset'), '4');
    assert.equal(store.pending().length, 1);
    assert.equal(store.recent('-200').length, 0);
    assert.equal(store.recent('-100')[0]!.text, 'hello');
    store.ingest(update(0), ['-100']);
    assert.equal(store.get('offset'), '4', 'out-of-order update cannot rewind polling');
  } finally { db.close(); }
});

test('outbox deduplicates effects and binds IDs to method and trusted chat', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db);
    store.queue('one', '-100', 'sendMessage', { text: 'hi', chat_id: 'attacker-selected-chat' });
    store.queue('one', '-100', 'sendMessage', { text: 'hi', chat_id: 'other-ignored-chat' });
    assert.equal(store.outgoing().length, 1);
    assert.equal(JSON.parse(store.outgoing()[0]!.payload).chat_id, '-100');
    assert.throws(() => store.queue('one', '-200', 'sendMessage', { text: 'hi' }), /different|conflict/i);
    assert.throws(() => store.queue('one', '-100', 'sendPhoto', { text: 'hi' }), /different|conflict/i);
    assert.throws(() => store.queue('one', '-100', 'sendMessage', { text: 'different' }), /different|conflict/i);
  } finally { db.close(); }
});

test('crash recovery does not resend unknown effects and preserves already made attention decisions', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db);
    store.ingest(update(1), ['-100']);
    store.decide(1, { reply: false });
    store.decide(1, { reply: true });
    store.status(1, 'processing');
    store.queue('uncertain', '-100', 'sendMessage', { text: 'might have been sent' });
    store.queue('unattempted', '-100', 'sendMessage', { text: 'safe to send' });
    store.outgoingStatus('uncertain', 'sending');
    const restarted = new Store(db);
    assert.equal(restarted.pending().length, 0, 'opening a maintenance connection cannot recover a live task');
    restarted.recoverInterrupted();
    assert.equal(restarted.pending().length, 1);
    assert.deepEqual(JSON.parse(restarted.pending()[0]!.decision!), { reply: false });
    assert.deepEqual(restarted.outgoing().map(x => x.id), ['unattempted']);
    assert.equal(db.prepare('SELECT status FROM outgoing WHERE id=?').get('uncertain')!.status, 'unknown');
    restarted.queue('uncertain', '-100', 'sendMessage', { text: 'might have been sent' });
    assert.deepEqual(restarted.outgoing().map(x => x.id), ['unattempted'], 'replayed intent must not reset unknown effect');
  } finally { db.close(); }
});

test('payment service messages remain durable but are excluded from conversational context', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db);
    const paid = update(1);
    paid.message!.successful_payment = { currency: 'XTR', total_amount: 25, invoice_payload: 'private-signature', telegram_payment_charge_id: 'private-charge-id' };
    const refunded = update(2);
    refunded.message!.refunded_payment = paid.message!.successful_payment;
    store.ingest(paid, ['-100']);
    store.ingest(refunded, ['-100']);
    store.ingest(update(3), ['-100']);
    assert.equal(store.pending().length, 3);
    assert.deepEqual(store.recent('-100').map(m => m.message_id), [3]);
    assert.ok(!JSON.stringify(store.recent('-100')).includes('private-'));
  } finally { db.close(); }
});

test('conversation cutoff excludes queued future messages and uses the latest edit available at that update', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db);
    store.ingest(update(1, -100, 'original before correction'), ['-100']);
    store.ingest(update(2, -100, 'question being answered'), ['-100']);
    store.ingest({ update_id: 3, edited_message: { ...update(1).message!, text: 'corrected after question' } }, ['-100']);
    store.ingest(update(4, -100, 'future message'), ['-100']);
    assert.deepEqual(store.conversation('-100', 2).map(message => message.text), ['original before correction', 'question being answered']);
    const revised = store.recent('-100', 30, 3);
    assert.equal(revised.filter(message => message.message_id === 1).length, 1);
    assert.equal(revised.find(message => message.message_id === 1)?.text, 'corrected after question');
    assert.equal(revised.some(message => message.text === 'future message'), false);
    assert.equal(store.recent('-100').find(message => message.message_id === 1)?.text, 'corrected after question');
  } finally { db.close(); }
});

test('conversation contains confirmed own replies in sequence, with future and uncertain sends excluded', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db);
    const first = update(1, -100, 'question'); first.message!.date = 100;
    const followup = update(3, -100, 'follow-up'); followup.message!.date = 300;
    store.ingest(first, ['-100']); store.ingest(followup, ['-100']);
    const sent = (id: string, messageId: number, date: number, text: string, status: string, chat = '-100') => {
      store.queue(id, chat, 'sendMessage', { text });
      store.outgoingStatus(id, status, { message_id: messageId, date, chat: { id: Number(chat) }, from: { id: 777, is_bot: true }, text });
    };
    sent('reply', 2, 200, 'my confirmed answer', 'sent');
    sent('future', 4, 400, 'my later answer', 'sent');
    sent('uncertain', 5, 250, 'might not have arrived', 'unknown');
    sent('elsewhere', 6, 250, 'another chat answer', 'sent', '-200');
    assert.deepEqual(store.conversation('-100', 3).map(message => message.text), ['question', 'my confirmed answer', 'follow-up']);
    assert.deepEqual(store.conversation('-100', 1).map(message => message.text), ['question']);
    assert.deepEqual(store.conversation('-100', 3, 2).map(message => message.text), ['my confirmed answer', 'follow-up']);
  } finally { db.close(); }
});
