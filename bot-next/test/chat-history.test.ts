import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { Store } from '../src/store.js';
import { MemoryStore } from '../src/memory.js';
import { historyCoverage, searchChatHistory } from '../src/chat-history.js';
import type { TelegramMessage } from '../src/telegram.js';

const epoch = (date: string) => Date.parse(date) / 1000;
const message = (id: number, text: string, extra: Partial<TelegramMessage> = {}): TelegramMessage => ({ message_id: id, date: epoch('2026-11-10T12:00:00Z') + id, chat: { id: -100 }, from: { id: 7, first_name: 'Ник' }, text, ...extra });
function fixture() { const db = new DatabaseSync(':memory:'); return { db, store: new Store(db), memory: new MemoryStore(db) }; }

test('history reaches earlier days outside the recent window and preserves original authors and dates', () => {
  const f = fixture();
  try {
    f.store.ingest({ update_id: 1, message: message(1, 'Играем в ДЕТЕКТИВА: улика — синий ключ.', { reply_to_message: message(200, 'Я веду расследование.', { from: { id: 777, first_name: 'Bot', is_bot: true } }) }) });
    for (let id = 2; id <= 40; id++) f.store.ingest({ update_id: id, message: message(id, 'Сегодня другая тема.', { date: epoch('2026-11-14T12:00:00Z') + id }) });
    assert.ok(!JSON.stringify(f.store.conversation('-100', 40)).includes('синий ключ'));
    const found = searchChatHistory(f.store, f.memory, '-100', 40, 'детектив');
    assert.equal(found.matches.length, 1); assert.equal(found.matches[0]!.message_id, 1);
    assert.match(found.matches[0]!.message, /telegram_user:7/); assert.match(found.matches[0]!.message, /2026-11-10/);
    assert.match(found.matches[0]!.reply!, /telegram_user:777/);
    assert.deepEqual(historyCoverage(f.store, '-100', 40), { storedMessages: 40, oldestMessageAt: '2026-11-10T12:00:01.000Z', newestMessageAt: '2026-11-14T12:00:40.000Z' });
  } finally { f.db.close(); }
});

test('current/future messages, other chats, forgotten sources and payment details stay outside search', () => {
  const f = fixture();
  try {
    f.store.ingest({ update_id: 1, message: message(1, 'Детектив: забыт весь старый контекст.') });
    f.store.ingest({ update_id: 2, message: message(2, 'Детектив: забытый факт.') });
    f.store.ingest({ update_id: 3, message: message(3, 'Детектив в другом чате', { chat: { id: -200 } }) });
    f.store.ingest({ update_id: 4, message: message(4, 'Детектив: скрытые платёжные сведения', { successful_payment: { currency: 'XTR', total_amount: 1, invoice_payload: 'secret', telegram_payment_charge_id: 'secret' } }) });
    f.store.ingest({ update_id: 5, message: message(5, 'Детектив: доступная реплика', { reply_to_message: message(2, 'Детектив: забытый факт.') }) });
    f.store.ingest({ update_id: 6, message: message(6, 'Детектив: текущий запрос') });
    f.store.ingest({ update_id: 7, message: message(7, 'Детектив: будущее') });
    f.store.set('memory:context-floor:-100', '1'); f.memory.forgetSources('-100', ['-100:2']);
    const found = searchChatHistory(f.store, f.memory, '-100', 6, 'ДЕТЕКТИВ');
    assert.deepEqual(found.matches.map(item => item.message_id), [5]); assert.equal(found.matches[0]!.reply, undefined);
    assert.ok(!JSON.stringify(found).includes('secret')); assert.ok(!JSON.stringify(found).includes('забытый факт'));
    assert.equal(historyCoverage(f.store, '-100', 6).storedMessages, 3);
  } finally { f.db.close(); }
});

test('pagination never resurrects old versions, and an edit after the snapshot cannot change its results', () => {
  const f = fixture();
  try {
    f.store.ingest({ update_id: 1, message: message(1, 'Детектив: старая версия') });
    f.store.ingest({ update_id: 2, message: message(2, 'Детектив: вторая реплика') });
    f.store.ingest({ update_id: 3, edited_message: { ...message(1, 'Детектив: новая версия'), edit_date: epoch('2026-11-11T12:00:00Z') } });
    const first = searchChatHistory(f.store, f.memory, '-100', 10, 'детектив', 1);
    assert.deepEqual(first.matches.map(item => item.update_id), [3]); assert.equal(first.next_before_update_id, 3);
    const second = searchChatHistory(f.store, f.memory, '-100', 10, 'детектив', 1, 'Asia/Yekaterinburg', first.next_before_update_id!);
    assert.deepEqual(second.matches.map(item => item.update_id), [2]); assert.equal(second.next_before_update_id, null);
    assert.equal(searchChatHistory(f.store, f.memory, '-100', 10, 'детектив', 1, 'Asia/Yekaterinburg', 2).matches.length, 0);
    f.store.ingest({ update_id: 11, edited_message: message(1, 'Убрали старую тему') });
    assert.match(searchChatHistory(f.store, f.memory, '-100', 10, 'детектив').matches[0]!.message, /новая версия/);
    assert.deepEqual(searchChatHistory(f.store, f.memory, '-100', 12, 'детектив').matches.map(item => item.message_id), [2]);
  } finally { f.db.close(); }
});

test('history accepts multiple literal keywords, validates bounds and has no calendar-day cutoff', () => {
  const f = fixture();
  try {
    f.store.ingest({ update_id: 1, message: message(1, 'Синий ключ нашли у детектива.', { date: epoch('2025-01-01T00:00:00Z') }) });
    assert.equal(searchChatHistory(f.store, f.memory, '-100', 2, 'СИНИЙ детектив').matches.length, 1);
    assert.equal(searchChatHistory(f.store, f.memory, '-100', 2, 'красный детектив').matches.length, 0);
    assert.equal(historyCoverage(f.store, '-200', 2).oldestMessageAt, null);
    for (const query of ['', 'x'.repeat(501)]) assert.throws(() => searchChatHistory(f.store, f.memory, '-100', 2, query));
    assert.throws(() => searchChatHistory(f.store, f.memory, '-100', 2, 'ключ', 0));
    assert.throws(() => searchChatHistory(f.store, f.memory, '-100', 2, 'ключ', 8, 'Asia/Yekaterinburg', 3));
  } finally { f.db.close(); }
});
