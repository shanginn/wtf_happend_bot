import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { Stars } from '../src/stars.js';
import { Store } from '../src/store.js';
import { Telegram, TelegramError, type TelegramUpdate } from '../src/telegram.js';
import { handleFinancialUpdate } from '../src/payments.js';

function fixture() {
  const db = new DatabaseSync(':memory:'), store = new Store(db);
  const stars = new Stars(db, { signingKey: 'x'.repeat(32), botId: 'bot' });
  const calls: { method: string; payload: Record<string, unknown> }[] = [];
  const telegram = new Telegram('fake', (async (url, init) => {
    calls.push({ method: String(url).split('/').at(-1)!, payload: JSON.parse(init!.body as string) });
    return new Response(JSON.stringify({ ok: true, result: true }));
  }) as typeof fetch);
  return { db, store, stars, calls, options: { stars, store, telegram, supportContact: '@owner', botUsername: 'thisbot' } };
}
function payment(payload: string, updateId = 1): TelegramUpdate {
  return { update_id: updateId, message: { message_id: updateId, date: 1, chat: { id: 99, type: 'private' }, from: { id: 99 },
    successful_payment: { currency: 'XTR', total_amount: 25, invoice_payload: payload, telegram_payment_charge_id: 'charge-1' } } };
}

test('successful credit and thank-you are atomic and repeat-safe, attributed to invoice chat', async () => {
  const f = fixture();
  try {
    const invoice = f.stars.createInvoice('-100', 25);
    const update = payment(invoice.payload);
    assert.equal(await handleFinancialUpdate(update, f.options), true);
    assert.equal(await handleFinancialUpdate(update, f.options), true);
    assert.equal(f.stars.chatBalance('-100'), 25);
    assert.equal(f.stars.chatBalance('99'), 0);
    assert.equal(f.store.outgoing().length, 1);
    assert.equal(f.store.outgoing()[0]!.chat, '-100');
    assert.equal(f.calls.length, 0, 'handler only queues delivery, no direct sending');
  } finally { f.db.close(); }
});

test('outbox database failure rolls back credit and propagates instead of quarantining valid money', async () => {
  const f = fixture();
  try {
    const update = payment(f.stars.createInvoice('-100', 25).payload);
    f.db.exec("CREATE TRIGGER fail_outgoing BEFORE INSERT ON outgoing BEGIN SELECT RAISE(ABORT, 'disk simulation'); END;");
    await assert.rejects(handleFinancialUpdate(update, f.options), /disk simulation/);
    assert.equal(f.stars.chatBalance('-100'), 0);
    f.db.exec('DROP TRIGGER fail_outgoing');
    await handleFinancialUpdate(update, f.options);
    assert.equal(f.stars.chatBalance('-100'), 25);
    assert.equal(f.store.outgoing().length, 1);
  } finally { f.db.close(); }
});

test('invalid or legacy payments and unknown refunds are quarantined without blocking cursor progress', async () => {
  const f = fixture();
  try {
    const update = payment('old-unrecognized-invoice');
    assert.equal(await handleFinancialUpdate(update, f.options), true);
    f.store.ingest(update, []);
    assert.equal(f.store.get('offset'), '2');
    assert.equal(f.stars.chatBalance('-100'), 0);
    assert.equal(f.db.prepare('SELECT COUNT(*) AS n FROM payment_quarantine').get()!.n, 1);
    await handleFinancialUpdate(update, f.options);
    assert.equal(f.db.prepare('SELECT COUNT(*) AS n FROM payment_quarantine').get()!.n, 1);
    const refund: TelegramUpdate = { update_id: 2, message: { message_id: 2, date: 1, chat: { id: 99 }, refunded_payment: update.message!.successful_payment } };
    assert.equal(await handleFinancialUpdate(refund, f.options), true);
    assert.equal(f.db.prepare('SELECT COUNT(*) AS n FROM payment_quarantine').get()!.n, 2);
  } finally { f.db.close(); }
});

test('pre-checkout validates synchronously; expired acknowledgements do not stop polling, DB failures do', async () => {
  const f = fixture();
  try {
    const invoice = f.stars.createInvoice('-100', 25);
    const update: TelegramUpdate = { update_id: 1, pre_checkout_query: { id: 'checkout', from: { id: 99 }, currency: 'XTR', total_amount: 25, invoice_payload: invoice.payload } };
    await handleFinancialUpdate(update, f.options);
    assert.deepEqual(f.calls[0], { method: 'answerPreCheckoutQuery', payload: { pre_checkout_query_id: 'checkout', ok: true } });
    const unavailable = { ...f.options, telegram: { call: async () => { throw new TelegramError('unknown'); } } };
    assert.equal(await handleFinancialUpdate(update, unavailable), true);
    const rejected = { ...update, pre_checkout_query: { ...update.pre_checkout_query!, total_amount: 26 } };
    await handleFinancialUpdate(rejected, f.options);
    assert.equal(f.calls[1]!.payload.ok, false);
    f.db.exec('DROP TABLE stars_invoices');
    await assert.rejects(handleFinancialUpdate(update, f.options), /no such table/);
    assert.equal(f.calls.length, 2);
  } finally { f.db.close(); }
});

test('paysupport is available outside conversational allowlist and respects another bot mention', async () => {
  const f = fixture();
  try {
    const update: TelegramUpdate = { update_id: 10, message: { message_id: 10, date: 1, chat: { id: 99, type: 'private' }, text: '/paysupport' } };
    assert.equal(await handleFinancialUpdate(update, f.options), true);
    assert.equal(f.store.ingest(update, ['-100']), false);
    const out = f.store.outgoing();
    assert.equal(out.length, 1);
    assert.equal(out[0]!.chat, '99');
    assert.match(JSON.parse(out[0]!.payload).text, /@owner/);
    assert.equal(await handleFinancialUpdate({ update_id: 11, message: { ...update.message!, text: '/paysupport@otherbot' } }, f.options), false);
    assert.equal(await handleFinancialUpdate({ update_id: 12, message: { ...update.message!, text: 'hello' } }, f.options), false);
  } finally { f.db.close(); }
});

test('Telegram image download cancels at 4 MB even if getFile understates actual size', async () => {
  let calls = 0, cancelled = false;
  const telegram = new Telegram('fake', (async (_url, init) => {
    if (++calls === 1) return new Response(JSON.stringify({ ok: true, result: { file_path: 'photos/test.jpg', file_size: 100 } }));
    assert.equal(init?.redirect, 'error');
    return new Response(new ReadableStream<Uint8Array>({
      pull(controller) { controller.enqueue(new Uint8Array(2_000_000)); },
      cancel() { cancelled = true; },
    }));
  }) as typeof fetch);
  await assert.rejects(telegram.image('file-id'), /too large/);
  assert.equal(cancelled, true);
});
