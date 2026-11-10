import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { Budget } from '../src/budget.js';
import { chooseReply, Jev, type AttentionState } from '../src/attention.js';

const state: AttentionState = { chatId: 'chat', explicit: false, recent: [{ text: 'hello' }], botName: 'bot', now: 1_000_000 };
const answers = (addressed: number) => Object.fromEntries(['addressed', 'continuation', 'useful', 'intrusive'].map(key => [key, { type: 'noul', noul: key === 'addressed' ? addressed : 0 }]));

test('Jev uses OpenRouter System One with the same account while preserving typed decisions and accounting', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 1_000_000);
    const request: typeof fetch = async (url, init) => {
      assert.equal(url, 'https://openrouter.ai/api/v1/systemone');
      assert.equal((init!.headers as Record<string, string>).authorization, 'Bearer offline-key');
      assert.equal(JSON.parse(String(init!.body)).model, 'typesafe/jev-1.14');
      return new Response(JSON.stringify({ answers: answers(0.99), usage: { input_tokens: 100 } }));
    };
    const decision = await new Jev('offline-key', 'typesafe/jev-1.14', budget, request, 'openrouter').decide(state);
    assert.equal(decision.reply, true);
    assert.equal(decision.fallback, undefined);
    assert.equal(budget.balance().spentMicros, 5);
  } finally { db.close(); }
});

test('Jev unknown, malformed, out-of-range and failed responses use quiet explicit-only fallback', async () => {
  for (const payload of [
    {}, { answers: { addressed: { type: 'unknown' } } },
    { answers: answers(1.01) }, { answers: answers(-0.01) },
    { answers: { ...answers(0.8), useful: { type: 'noul', noul: 'yes' } } },
  ]) {
    const db = new DatabaseSync(':memory:');
    try {
      const budget = new Budget(db, 1_000_000);
      const jev = new Jev('key', 'model', budget, (async () => new Response(JSON.stringify(payload))) as typeof fetch);
      assert.deepEqual(await jev.decide(state), { reply: false, model: 'model', fallback: true });
      assert.deepEqual(await jev.decide({ ...state, explicit: true }), { reply: true, model: 'model', fallback: true });
      assert.ok(budget.balance().reservedMicros > 0, 'unknown paid outcomes retain their reservation');
    } finally { db.close(); }
  }
});

test('Jev transport errors and exhausted budget do not make unsolicited replies', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    let requests = 0;
    const jev = new Jev('key', 'model', new Budget(db, 0), (async () => { requests++; throw new Error('network'); }) as typeof fetch);
    assert.equal((await jev.decide(state)).reply, false);
    assert.equal((await jev.decide({ ...state, explicit: true })).reply, true);
    assert.equal(requests, 0);
  } finally { db.close(); }
  const db2 = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db2, 1_000_000);
    const jev = new Jev('key', 'model', budget, (async () => { throw new Error('network'); }) as typeof fetch);
    assert.equal((await jev.decide(state)).fallback, true);
    assert.ok(budget.balance().reservedMicros > 0);
  } finally { db2.close(); }
});

test('oversized Jev context falls back before spending instead of blocking ingestion', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    let requests = 0;
    const budget = new Budget(db, 1_000_000);
    const jev = new Jev('key', 'model', budget, (async () => { requests++; throw new Error('must not run'); }) as typeof fetch);
    const decision = await jev.decide({ ...state, recent: ['x'.repeat(50_000)] });
    assert.equal(decision.fallback, true);
    assert.equal(decision.reply, false);
    assert.equal(requests, 0);
    assert.equal(budget.balance().reservedMicros, 0);
  } finally { db.close(); }
});

test('valid Jev decision settles usage; unsolicited comments respect cooldown', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 1_000_000);
    const jev = new Jev('key', 'model', budget, (async () => new Response(JSON.stringify({ model: 'returned-model', answers: answers(0.9), usage: { input_tokens: 100 } }))) as typeof fetch);
    const decision = await jev.decide(state);
    assert.equal(decision.reply, true);
    assert.equal(decision.model, 'returned-model');
    assert.equal(budget.balance().spentMicros, 5);
    assert.equal(budget.balance().reservedMicros, 0);
    assert.equal(chooseReply({ useful: 0.95, intrusive: 0.1 }, { ...state, lastSpokeAt: state.now - 60_000 }), false);
    assert.equal(chooseReply({ useful: 0.95, intrusive: 0.1 }, { ...state, lastSpokeAt: state.now - 180_000 }), true);
  } finally { db.close(); }
});

test('stable event IDs reuse completed decisions and never resubmit unknown paid calls', async () => {
  for (const succeeds of [true, false]) {
    const db = new DatabaseSync(':memory:');
    try {
      let calls = 0;
      const budget = new Budget(db, 1_000_000);
      const request = (async () => {
        calls++;
        if (!succeeds) throw new Error('response lost');
        return new Response(JSON.stringify({ answers: answers(0.9), usage: { input_tokens: 100 } }));
      }) as typeof fetch;
      const input = { ...state, eventId: 'update-1' };
      const first = await new Jev('key', 'model', budget, request).decide(input);
      const before = budget.balance();
      const repeated = await new Jev('key', 'model', budget, request).decide(input);
      assert.deepEqual(repeated, first);
      assert.equal(calls, 1);
      assert.deepEqual(budget.balance(), before);
    } finally { db.close(); }
  }
});
