import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { Stars } from '../src/stars.js';
import { Budget } from '../src/budget.js';

test('validated Telegram charges credit the invoice chat once without creating USD funds', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const stars = new Stars(db, { signingKey: 'x'.repeat(32), botId: 'bot1' });
    const budget = new Budget(db, 100);
    const invoice = stars.createInvoice('-100', 25);
    assert.ok(Buffer.byteLength(invoice.payload) <= 128);
    assert.deepEqual(stars.validatePreCheckout({ invoice_payload: invoice.payload, currency: 'XTR', total_amount: 25 }), { ok: true });
    const payment = { chargeId: 'charge1', payerId: 'user1', payload: invoice.payload, currency: 'XTR', totalAmount: 25 };
    assert.equal(stars.recordPayment(payment).credited, true);
    assert.equal(stars.recordPayment(payment).credited, false);
    assert.equal(stars.chatBalance('-100'), 25);
    assert.equal(stars.chatBalance('-200'), 0);
    assert.equal(budget.balance().availableMicros, 100);
    assert.throws(() => stars.recordPayment({ ...payment, payerId: 'someone-else' }), /conflict/);
    assert.equal(stars.recordRefund('charge1'), true);
    assert.equal(stars.recordRefund('charge1'), false);
    assert.equal(stars.chatBalance('-100'), 0);
  } finally { db.close(); }
});

test('tampered, mismatched and expired invoices cannot pass checkout; delayed payment can settle', () => {
  const db = new DatabaseSync(':memory:');
  try {
    let now = 0;
    const stars = new Stars(db, { signingKey: 'x'.repeat(32), botId: 'bot1', now: () => now });
    const invoice = stars.createInvoice('-100', 25);
    for (const query of [
      { invoice_payload: invoice.payload + 'x', currency: 'XTR', total_amount: 25 },
      { invoice_payload: invoice.payload, currency: 'USD', total_amount: 25 },
      { invoice_payload: invoice.payload, currency: 'XTR', total_amount: 24 },
    ]) assert.equal(stars.validatePreCheckout(query).ok, false);
    now = 86_400_001;
    assert.equal(stars.validatePreCheckout({ invoice_payload: invoice.payload, currency: 'XTR', total_amount: 25 }).ok, false);
    assert.equal(stars.recordPayment({ chargeId: 'late', payerId: 'u', payload: invoice.payload, currency: 'XTR', totalAmount: 25 }).credited, true);
    const otherBot = new Stars(db, { signingKey: 'x'.repeat(32), botId: 'bot2' });
    assert.equal(otherBot.validatePreCheckout({ invoice_payload: invoice.payload, currency: 'XTR', total_amount: 25 }).ok, false);
  } finally { db.close(); }
});

test('replayed invoice creation preserves payload and expiry and rejects changed intent', () => {
  const db = new DatabaseSync(':memory:');
  try {
    let now = 0;
    const stars = new Stars(db, { signingKey: 'x'.repeat(32), botId: 'bot1', now: () => now });
    const invoice = stars.createInvoice('-100', 25, 'task-1');
    now = 86_400_001;
    assert.deepEqual(stars.createInvoice('-100', 25, 'task-1'), invoice);
    assert.equal(stars.validatePreCheckout({ invoice_payload: invoice.payload, currency: 'XTR', total_amount: 25 }).ok, false);
    assert.throws(() => stars.createInvoice('-200', 25, 'task-1'), /conflict/);
    assert.throws(() => stars.createInvoice('-100', 26, 'task-1'), /conflict/);
  } finally { db.close(); }
});
