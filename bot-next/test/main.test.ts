import test from 'node:test';
import assert from 'node:assert/strict';
import { projectMessage } from '../src/main.js';
import type { TelegramMessage } from '../src/telegram.js';

test('model projection excludes payment IDs, signatures, file IDs and nested message bodies', () => {
  const payment = { currency: 'XTR', total_amount: 25, invoice_payload: 'secret-invoice-signature', telegram_payment_charge_id: 'secret-charge' };
  const message: TelegramMessage = {
    message_id: 10, date: 1_700_000_000, chat: { id: -100 }, from: { id: 7, first_name: 'Иван', username: 'ivan' },
    text: 'Спасибо!', successful_payment: payment,
    photo: [{ file_id: 'secret-file-id', width: 100, height: 100 }],
    reply_to_message: { message_id: 9, date: 1_700_000_000, chat: { id: -100 }, text: 'nested-private-body', refunded_payment: payment },
  };
  const result = projectMessage(message);
  assert.equal(result.text, 'Спасибо!');
  assert.equal(result.image, true);
  assert.equal(result.replyTo, 9);
  assert.deepEqual(result.author, { id: 7, name: 'Иван', username: 'ivan' });
  const encoded = JSON.stringify(result);
  for (const secret of ['secret-invoice-signature', 'secret-charge', 'secret-file-id', 'nested-private-body', 'successful_payment', 'refunded_payment']) assert.ok(!encoded.includes(secret));
});

test('projection bounds text and supports captions and channel senders without changing input', () => {
  const message: TelegramMessage = { message_id: 1, date: 1_700_000_000, chat: { id: -100 }, sender_chat: { id: -200, title: 'channel' }, caption: 'x'.repeat(9000) };
  const result = projectMessage(message);
  assert.equal(result.text.length, 8000);
  assert.equal(message.caption!.length, 9000);
  assert.deepEqual(result.author, { senderChat: -200 });
  assert.equal(result.image, false);
});
