import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { Budget } from '../src/budget.js';
import { Media } from '../src/media.js';

const options = { apiKey: 'test-key', endpoint: 'fal-ai/flux/schnell', priceMicros: 30 };
const json = (body: unknown, status = 200) => new Response(JSON.stringify(body), { status });

test('concurrent generation retries submit once and reserve once', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 50);
    let submissions = 0;
    let resolve!: (response: Response) => void;
    const pending = new Promise<Response>(r => { resolve = r; });
    const media = new Media(db, budget, { ...options, fetch: (async () => { submissions++; return pending; }) as typeof fetch });
    const first = media.generate({ operationId: 'one', chatId: 'chat', prompt: 'cat' });
    assert.equal((await media.generate({ operationId: 'one', chatId: 'chat', prompt: 'cat' })).state, 'submitting');
    await assert.rejects(media.generate({ operationId: 'two', chatId: 'chat', prompt: 'dog' }), /Budget exhausted/);
    resolve(json({ request_id: 'request-1' }));
    assert.equal((await first).state, 'queued');
    assert.equal(submissions, 1);
    assert.equal(budget.balance().reservedMicros, 30);
  } finally { db.close(); }
});

test('ambiguous submission survives restart without resubmitting or releasing reservation', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 50);
    let calls = 0;
    const fetcher = (async () => { calls++; throw new Error('connection lost after accepting request'); }) as typeof fetch;
    const media = new Media(db, budget, { ...options, fetch: fetcher });
    const input = { operationId: 'one', chatId: 'chat', prompt: 'cat' };
    assert.equal((await media.generate(input)).state, 'unknown');
    const restarted = new Media(db, budget, { ...options, fetch: fetcher });
    assert.equal((await restarted.generate(input)).state, 'unknown');
    assert.equal((await restarted.reconcile('one')).state, 'unknown');
    assert.equal(calls, 1);
    assert.equal(budget.balance().reservedMicros, 30);
  } finally { db.close(); }
});

test('polling after restart stores result and settles once; duplicate completion is harmless', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 50);
    const fetcher = (async (url: string | URL | Request, init?: RequestInit) => {
      if (init?.method === 'POST') return json({ request_id: 'request-1' });
      if (String(url).endsWith('/status')) return json({ status: 'COMPLETED' });
      return json({ images: [{ url: 'https://fal.media/cat.png' }] });
    }) as typeof fetch;
    const media = new Media(db, budget, { ...options, fetch: fetcher });
    await media.generate({ operationId: 'one', chatId: 'chat', prompt: 'cat' });
    const restarted = new Media(db, budget, { ...options, fetch: fetcher });
    await Promise.all([restarted.reconcile('one'), restarted.reconcile('one')]);
    assert.equal(restarted.get('one')!.state, 'succeeded');
    assert.deepEqual(restarted.get('one')!.result, { images: [{ url: 'https://fal.media/cat.png' }] });
    assert.equal(budget.balance().spentMicros, 30);
    assert.equal(budget.balance().reservedMicros, 0);
  } finally { db.close(); }
});

test('definite request rejection releases money; server uncertainty keeps it reserved', async () => {
  for (const status of [422, 503]) {
    const db = new DatabaseSync(':memory:');
    try {
      const budget = new Budget(db, 50);
      const media = new Media(db, budget, { ...options, fetch: (async () => json({}, status)) as typeof fetch });
      const job = await media.generate({ operationId: 'one', chatId: 'chat', prompt: 'cat' });
      assert.equal(job.state, status === 422 ? 'failed' : 'unknown');
      assert.equal(budget.balance().reservedMicros, status === 422 ? 0 : 30);
    } finally { db.close(); }
  }
});

test('untrusted follow-up URL is never fetched and redirects are disabled', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    let calls = 0;
    const budget = new Budget(db, 50);
    const media = new Media(db, budget, { ...options, fetch: (async (_url, init) => {
      calls++;
      assert.equal(init?.redirect, 'error');
      return json({ request_id: 'request-1', status_url: 'https://attacker.invalid/status' });
    }) as typeof fetch });
    assert.equal((await media.generate({ operationId: 'one', chatId: 'chat', prompt: 'cat' })).state, 'unknown');
    await media.reconcile('one');
    assert.equal(calls, 1);
    assert.equal(budget.balance().reservedMicros, 30);
  } finally { db.close(); }
});
