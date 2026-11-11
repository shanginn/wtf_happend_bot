import test from 'node:test';
import assert from 'node:assert/strict';
import { buildTelegramInput, formatTelegramEvent, formatTelegramTimestamp, participantReference } from '../src/telegram-context.js';
import type { TelegramMessage } from '../src/telegram.js';
import type { ConversationEvent } from '../src/store.js';

const chat = { id: -100, type: 'supergroup', title: 'ОрХив' };
const nick = { id: 7, first_name: 'Ник', username: 'nick', is_bot: false };
const dmitry = { id: 8, first_name: 'Дмитрий', last_name: 'Ж', username: 'dmitry', is_bot: false };
const bot = { id: 777, first_name: 'WTF happend??', username: 'wtf_happend_bot', is_bot: true };
const options = { timezone: 'Asia/Yekaterinburg', bot };
const message = (id: number, from = nick, text = 'hello'): TelegramMessage => ({ message_id: id, chat, from, date: 1794381617, text });
const event = (m: TelegramMessage, updateId = 42): ConversationEvent => ({ message: m, updateId, kind: 'message' });

test('predecessor format preserves From, message/update IDs, explicit local date and immutable participant reference', () => {
  const view = formatTelegramEvent(event({ ...message(10, dmitry), sender_tag: 'топ пиздабол' }), options);
  for (const expected of ['Telegram update: message', 'Update id: 42', 'Chat: supergroup "ОрХив" (id -100)', 'From: Дмитрий Ж (@dmitry, id 8)', 'Sender kind: user', 'Message id: 10', 'Sent at: 2026-11-11 12:20:17 +05:00', 'Sender tag: топ пиздабол', 'Text:\nhello', 'Participant reference: telegram_user:8']) assert.ok(view.includes(expected), expected);
  assert.equal(formatTelegramTimestamp(1794381617, 'UTC'), '2026-11-11 07:20:17 +00:00');
  assert.equal(participantReference(message(1, dmitry)), 'telegram_user:8');
});
test('screen example keeps each speaker separate, marks the bot and includes the current message only once', () => {
  const archive = event(message(10, dmitry, 'ну короче чат в архив улетает пон'));
  const joke = event(message(11, nick, 'потому что шутки твои не оценили?'));
  const own = { message: message(12, bot, 'Ошибочно приписал шутку Дмитрию'), kind: 'confirmed bot output' as const };
  const current = event(message(13, nick, 'бот лайкни мем димона'));
  const blocks = buildTelegramInput({ ...options, current, history: [archive, joke, own, current], now: 1794381629790, memories: [] });
  const rendered = blocks.flatMap(block => block.type === 'text' ? [block.text] : []).join('\n');
  assert.equal((rendered.match(/Message id: 13\n/g) ?? []).length, 1);
  assert.equal(blocks.length, 6, 'clock/self identity, three separate previous-message blocks, current message, memory/skills');
  assert.ok(rendered.includes('Sender kind: bot (this bot)'));
  assert.ok(rendered.includes('Bot identity: WTF happend?? (@wtf_happend_bot, id 777)'));
  assert.ok(rendered.includes('Current time: 2026-11-11 12:20:29 +05:00'));
  const history = blocks[2]!; assert.equal(history.type, 'text'); if (history.type === 'text') {
    assert.ok(history.text.includes('From: Ник (@nick, id 7)'));
    assert.ok(history.text.includes('Text:\nпотому что шутки твои не оценили?'));
  }
  assert.ok(!rendered.includes('Recent context: [{'));
});
test('reply outside the history window retains the original author, timestamp, preview and separate quote', () => {
  const view = formatTelegramEvent(event({ ...message(15, nick, 'это сказал он'), reply_to_message: message(2, dmitry, 'Скриншотов накидай тогда что ли'), quote: { text: 'Скриншотов накидай' } }), options);
  assert.ok(view.includes('From: Ник (@nick, id 7)'));
  assert.ok(view.includes('Reply to: #2 by Дмитрий Ж (@dmitry, id 8) [telegram_user:8], sent at 2026-11-11 12:20:17 +05:00'));
  assert.ok(view.includes('"Скриншотов накидай тогда что ли"'));
  assert.ok(view.includes('Quoted fragment:\nСкриншотов накидай'));
});
test('sender_chat takes precedence over the anonymous compatibility user in current messages and replies', () => {
  const anonymous = { ...message(2, { id: 1087968824, first_name: 'GroupAnonymousBot', is_bot: true, username: 'anonymous' }), sender_chat: { id: -777, type: 'channel', title: 'Announcements' } };
  const view = formatTelegramEvent(event({ ...anonymous, reply_to_message: anonymous }), options);
  assert.ok(view.includes('From: chat channel "Announcements" (id -777)'));
  assert.ok(view.includes('Reply to: #2 by chat channel "Announcements" (id -777)'));
  assert.ok(view.includes('Participant reference: telegram_chat:-777'));
  assert.ok(!view.includes('GroupAnonymousBot'));
});
test('edited and forwarded text preserve revision time and the original forwarded author', () => {
  const view = formatTelegramEvent({ ...event({ ...message(10), edit_date: 1794381637, forward_origin: { type: 'user', sender_user: dmitry, date: 1794381517 } }), kind: 'edited message' }, options);
  assert.ok(view.includes('Edited at: 2026-11-11 12:20:37 +05:00'));
  assert.ok(view.includes('Forwarded from: Дмитрий Ж (@dmitry, id 8), sent at 2026-11-11 12:18:37 +05:00'));
  assert.ok(view.includes('- edited a text message'));
});
test('forgotten reply sources and quoted fragments cannot reintroduce removed content', () => {
  const secret = 'FORGOTTEN_SECRET_4821';
  const view = formatTelegramEvent(event({ ...message(10), reply_to_message: message(2, dmitry, secret), quote: { text: secret } }), { ...options, canShowSource: source => source.message_id !== 2 });
  assert.ok(!view.includes(secret)); assert.ok(view.includes('content withheld'));
});
test('financial secrets remain excluded while supported file identifiers stay available to Telegram tools', () => {
  const payment = { currency: 'XTR', total_amount: 1, invoice_payload: 'private-signature', telegram_payment_charge_id: 'private-charge' };
  const view = formatTelegramEvent(event({ ...message(10), successful_payment: payment, text: 'private-body' }), options);
  for (const secret of ['private-signature', 'private-charge', 'private-body']) assert.ok(!view.includes(secret));
  const reply = formatTelegramEvent(event({ ...message(11), reply_to_message: { ...message(10), successful_payment: payment, text: 'private-body' }, quote: { text: 'private-body' } }), options);
  assert.ok(!reply.includes('private-body'));
  const photo = formatTelegramEvent(event({ ...message(11), photo: [{ file_id: 'reusable-photo', width: 100, height: 100 }] }), options);
  assert.ok(photo.includes('reusable-photo')); assert.ok(photo.includes('- sent a photo'));
});
