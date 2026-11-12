import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Store } from '../src/store.js';
import { Replies, ReplyProtocolError, splitReply } from '../src/replies.js';

function fixture() { const db = new DatabaseSync(':memory:'), store = new Store(db), replies = new Replies(store); return { db, store, replies }; }
const route = { chat: '-100', messageId: 42, topic: 17 };
const draft = { chat_id: '-100', text: 'Предварительный текст.' };

test('only the canonical final enters the outbox; drafts and final wording never compete for delivery', () => {
  const f = fixture();
  try {
    const context = f.replies.open('-100', 'telegram:1', route);
    const receipt = f.replies.draft(context, 'tool:1', '-100', 'sendMessage', draft);
    assert.deepEqual(receipt, { ok: true, delivery: 'draft', reply_id: context.id, sent: false }); assert.ok(!('message_id' in receipt));
    assert.equal(f.store.outgoing().length, 0);
    const final = 'Полный финал. Важные сведения, которых не было в черновике.';
    f.replies.complete(context, final); f.replies.complete(context, final);
    const out = f.store.outgoing(); assert.equal(out.length, 1); assert.equal(out[0]!.method, 'sendMessage');
    assert.deepEqual(JSON.parse(out[0]!.payload), { chat_id: '-100', text: final, message_thread_id: 17, reply_parameters: { message_id: 42, allow_sending_without_reply: true } });
    assert.equal(f.replies.row(context.id)!.final_text, final);
    assert.throws(() => f.replies.complete(context, 'Другой текст после commit'), error => error instanceof ReplyProtocolError && error.code === 'FINAL_REPLY_IMMUTABLE');
    f.replies.fail(context, 'Нельзя дописывать ошибку после готового ответа.'); assert.equal(f.store.outgoing().length, 1);
  } finally { f.db.close(); }
});

test('reply identity, draft intent and destination are immutable, while equivalent route property order is accepted', () => {
  const f = fixture();
  try {
    const context = f.replies.open('-100', 'request:1', route);
    assert.deepEqual(f.replies.open('-100', 'request:1', { topic: 17, messageId: 42, chat: '-100' }), context);
    assert.throws(() => f.replies.open('-100', 'request:1', { ...route, messageId: 43 }), /REPLY_IDENTITY_CONFLICT/);
    const first = f.replies.draft(context, 'tool:1', '-100', 'sendMessage', draft);
    assert.deepEqual(f.replies.draft(context, 'tool:1', '-100', 'sendMessage', draft), first);
    assert.throws(() => f.replies.draft(context, 'tool:1', '-100', 'sendMessage', { ...draft, text: 'Changed' }), /REPLY_DRAFT_OPERATION_CONFLICT/);
    assert.throws(() => f.replies.draft(context, 'wrong:chat', '-100', 'sendMessage', { ...draft, chat_id: '-200' }), /REPLY_TARGET_MISMATCH/);
    assert.throws(() => f.replies.draft(context, 'wrong:thread', '-100', 'sendMessage', { ...draft, message_thread_id: 99 }), /REPLY_TOPIC_MISMATCH/);
    assert.throws(() => f.replies.draft(context, 'raw:format', '-100', 'sendMessage', { ...draft, parse_mode: 'HTML' }), /REPLY_DRAFT_OPTIONS_REQUIRE_EXPLICIT_ACTION/);
    assert.equal(f.store.outgoing().length, 0);
  } finally { f.db.close(); }
});

test('a draft cannot masquerade as an empty completed reply; explicit failure has one canonical notice', () => {
  const f = fixture();
  try {
    const context = f.replies.open('-100', 'missing:final', route);
    f.replies.draft(context, 'tool:1', '-100', 'sendMessage', draft);
    assert.throws(() => f.replies.complete(context, ''), /FINAL_REPLY_REQUIRED_AFTER_DRAFT/);
    assert.equal(f.replies.row(context.id)!.committed, 0); assert.equal(f.store.outgoing().length, 0);
    f.replies.fail(context, 'Не удалось закончить ответ.'); f.replies.fail(context, 'Не удалось закончить ответ.');
    assert.equal(f.store.outgoing().length, 1); assert.equal(JSON.parse(f.store.outgoing()[0]!.payload).text, 'Не удалось закончить ответ.');
  } finally { f.db.close(); }
});

test('canonical final, outbox parts and ingress completion commit atomically', () => {
  const f = fixture();
  try {
    f.store.ingest({ update_id: 1, message: { message_id: 42, chat: { id: -100 }, date: 1, text: 'Request' } }); f.store.status(1, 'processing');
    const context = f.replies.open('-100', 'telegram:1', route, 1);
    f.db.exec("CREATE TRIGGER fail_reply_insert BEFORE INSERT ON outgoing BEGIN SELECT RAISE(ABORT,'injected outbox failure'); END;");
    assert.throws(() => f.replies.complete(context, 'Final'), /injected outbox failure/);
    assert.equal(f.replies.row(context.id)!.committed, 0); assert.equal(f.store.outgoing().length, 0);
    assert.equal(f.db.prepare('SELECT status FROM ingress WHERE id=1').get()!.status, 'processing');
    assert.equal(f.db.prepare('SELECT COUNT(*) AS count FROM reply_parts').get()!.count, 0);
    f.db.exec('DROP TRIGGER fail_reply_insert'); f.replies.complete(context, 'Final');
    assert.equal(f.replies.row(context.id)!.committed, 1); assert.equal(f.store.outgoing().length, 1);
    assert.equal(f.db.prepare('SELECT status FROM ingress WHERE id=1').get()!.status, 'done');
  } finally { f.db.close(); }
});

test('long replies retain every character and deliver deterministic parts in order; unknown delivery blocks successors', () => {
  const f = fixture();
  try {
    const final = 'x'.repeat(3999) + '🙂' + 'я'.repeat(8100) + '\nВажное окончание.';
    const parts = splitReply(final); assert.equal(parts.join(''), final); assert.ok(parts.every(part => part.length <= 4000 && !/[\uD800-\uDBFF]$/.test(part)));
    const context = f.replies.open('-100', 'long:reply', route); f.replies.complete(context, final);
    let next = f.store.outgoing(); assert.equal(next.length, 1); assert.equal(JSON.parse(next[0]!.payload).text, parts[0]);
    assert.equal(f.store.claimOutgoing(next[0]!.id), true); assert.equal(f.store.claimOutgoing(next[0]!.id), false);
    assert.equal(f.store.outgoing().length, 0); f.store.outgoingStatus(next[0]!.id, 'sent', { message_id: 10 });
    next = f.store.outgoing(); assert.equal(next.length, 1); assert.equal(JSON.parse(next[0]!.payload).text, parts[1]);
    assert.equal(f.store.claimOutgoing(next[0]!.id), true); f.store.recoverInterrupted();
    assert.equal(f.store.outgoing().length, 0); f.replies.complete(context, final); assert.equal(f.store.outgoing().length, 0);
    assert.equal(f.replies.row(context.id)!.final_text, final);
    assert.equal(f.db.prepare("SELECT COUNT(*) AS count FROM outgoing WHERE status='unknown'").get()!.count, 1);
  } finally { f.db.close(); }
});

test('restart before finalization resumes one immutable reply; restart after dispatch never re-sends an unknown POST', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'wtf-reply-protocol-')), path = join(directory, 'host.sqlite');
  let db = new DatabaseSync(path);
  try {
    let store = new Store(db), replies = new Replies(store), context = replies.open('-100', 'restart:1', route);
    replies.draft(context, 'tool:1', '-100', 'sendMessage', draft);
    db.close(); db = new DatabaseSync(path); store = new Store(db); replies = new Replies(store); store.recoverInterrupted();
    context = replies.open('-100', 'restart:1', route); replies.complete(context, 'Полный окончательный ответ.');
    const out = store.outgoing()[0]!; assert.equal(store.claimOutgoing(out.id), true);
    db.close(); db = new DatabaseSync(path); store = new Store(db); replies = new Replies(store); store.recoverInterrupted();
    replies.complete(context, 'Полный окончательный ответ.');
    assert.equal(store.outgoing().length, 0); assert.equal(db.prepare('SELECT status FROM outgoing WHERE id=?').get(out.id)!.status, 'unknown');
    assert.equal(db.prepare('SELECT COUNT(*) AS count FROM outgoing').get()!.count, 1);
  } finally { db.close(); await rm(directory, { recursive: true, force: true }); }
});

test('protocol-v0 recovery preserves a different final by editing the known message, never by suppressing it', () => {
  const f = fixture();
  try {
    const context = f.replies.open('-100', 'legacy:1', route), final = 'Другой финал с новым важным дополнением.';
    f.replies.adoptLegacy(context, [{ message_id: 77, chat: { id: -100 }, text: 'Старый промежуточный ответ.' }], false);
    f.replies.complete(context, final);
    const out = f.store.outgoing(); assert.equal(out.length, 1); assert.equal(out[0]!.method, 'editMessageText');
    assert.deepEqual(JSON.parse(out[0]!.payload), { chat_id: '-100', message_id: 77, text: final });
    assert.equal(f.replies.row(context.id)!.final_text, final);
  } finally { f.db.close(); }
});

test('ambiguous legacy delivery retains the full final for reconciliation and never creates another message', () => {
  const f = fixture();
  try {
    const context = f.replies.open('-100', 'legacy:unknown', route);
    f.replies.adoptLegacy(context, [], true); f.replies.complete(context, 'Сохранённый полный финал.');
    assert.equal(f.store.outgoing().length, 0); assert.equal(f.replies.row(context.id)!.final_text, 'Сохранённый полный финал.');
    assert.equal(f.replies.row(context.id)!.blocked, 'LEGACY_DELIVERY_UNKNOWN');
  } finally { f.db.close(); }
});

test('startup adopts an old queued canonical answer atomically rather than recomputing and sending it twice', () => {
  const f = fixture();
  try {
    f.store.ingest({ update_id: 1, message: { message_id: 42, chat: { id: -100 }, date: 1, text: 'Request' } }); f.store.status(1, 'processing');
    f.store.queue('answer:1', '-100', 'sendMessage', { text: 'Уже выбранный старой версией финал.', reply_parameters: { message_id: 42 } });
    f.store.queue('failure:1', '-100', 'sendMessage', { text: 'Лишнее сообщение об ошибке.' });
    assert.equal(f.replies.recoverLegacyOutbox(), 1); assert.equal(f.replies.recoverLegacyOutbox(), 0);
    assert.equal(f.store.pending().length, 0); assert.equal(f.store.outgoing().length, 1);
    assert.equal(f.store.outgoing()[0]!.id, 'answer:1');
    assert.equal(f.db.prepare("SELECT status FROM outgoing WHERE id='failure:1'").get()!.status, 'superseded');
    assert.equal(f.db.prepare('SELECT COUNT(*) AS count FROM reply_turns').get()!.count, 1);
  } finally { f.db.close(); }
});

test('an already-sent or unknown old answer wins over a leftover failure notice during upgrade', () => {
  for (const status of ['sent', 'unknown']) {
    const f = fixture();
    try {
      f.store.ingest({ update_id: 1, message: { message_id: 42, chat: { id: -100 }, date: 1, text: 'Request' } }); f.store.status(1, 'failed');
      f.store.queue('answer:1', '-100', 'sendMessage', { text: 'Сохранённый ответ.' }); f.store.outgoingStatus('answer:1', status);
      f.store.queue('failure:1', '-100', 'sendMessage', { text: 'Лишняя ошибка.' });
      assert.equal(f.replies.recoverLegacyOutbox(), 1); assert.equal(f.store.outgoing().length, 0);
      assert.equal(f.db.prepare('SELECT outgoing_id FROM reply_parts').get()!.outgoing_id, 'answer:1');
      assert.equal(f.db.prepare("SELECT status FROM outgoing WHERE id='failure:1'").get()!.status, 'superseded');
    } finally { f.db.close(); }
  }
});
