import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { atomic, Budget } from '../src/budget.js';

test('reservations include concurrent work; duplicate requests do not spend twice', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 100);
    const results = await Promise.allSettled([1, 2].map(i => Promise.resolve().then(() => budget.reserve(`op${i}`, 'chat', 60))));
    assert.equal(results.filter(r => r.status === 'fulfilled').length, 1);
    assert.equal(budget.balance().availableMicros, 40);
    assert.equal(budget.reserve('op1', 'chat', 60).state, 'reserved');
    assert.throws(() => budget.reserve('op1', 'other-chat', 60), /conflict/);
    assert.throws(() => budget.reserve('op1', 'chat', 61), /conflict/);
    budget.settle('op1', 50);
    budget.settle('op1', 50);
    assert.deepEqual(budget.balance(), { limitMicros: 100, spentMicros: 50, reservedMicros: 0, availableMicros: 50 });
    assert.throws(() => budget.release('op1'), /settled/);
  } finally { db.close(); }
});

test('a failed transaction leaves no partial reservation; unsafe money is rejected', () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 100);
    for (const amount of [-1, 0.5, Infinity, Number.MAX_SAFE_INTEGER + 1]) assert.throws(() => budget.reserve('bad', 'chat', amount), /Invalid/);
    atomic(db, () => {
      assert.throws(() => atomic(db, () => {
        budget.reserve('rollback', 'chat', 30);
        throw new Error('later operation failed');
      }), /later operation failed/);
    });
    assert.equal(budget.get('rollback'), undefined);
    budget.reserve('ok', 'chat', 20);
    assert.throws(() => budget.settle('ok', 21), /exceeds/);
    assert.equal(budget.balance().reservedMicros, 20);
    budget.release('ok'); budget.release('ok');
    assert.equal(budget.balance().availableMicros, 100);
    new Budget(db, 10_000);
    assert.equal(budget.balance().limitMicros, 100, 'restart cannot silently grant more funds');
  } finally { db.close(); }
});
