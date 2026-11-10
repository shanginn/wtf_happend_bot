import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Budget } from '../src/budget.js';
import { Media } from '../src/media.js';
import { Store } from '../src/store.js';
import { queueCompletedMedia } from '../src/delivery.js';

const options = { apiKey: 'offline-key', endpoint: 'fal-ai/flux/schnell', priceMicros: 30 };
const json = (value: unknown, status = 200) => new Response(JSON.stringify(value), { status });

test('restart reconstructs completed media delivery once with its original topic and reply', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'wtf-delivery-test-'));
  const path = join(directory, 'state.sqlite');
  let db = new DatabaseSync(path);
  let requests = 0;
  const fetcher = (async (url: string | URL | Request, init?: RequestInit) => {
    requests++;
    if (init?.method === 'POST') return json({ request_id: 'request-1' });
    if (String(url).endsWith('/status')) return json({ status: 'COMPLETED' });
    return json({ images: [{ url: 'https://fal.media/offline-cat.png' }] });
  }) as typeof fetch;
  try {
    const store = new Store(db), budget = new Budget(db, 100);
    const media = new Media(db, budget, { ...options, fetch: fetcher });
    store.set('route:image:one', JSON.stringify({ messageId: 42, topic: 17 }));
    store.set('route:-100', JSON.stringify({ messageId: 999, topic: 88 }));
    await media.generate({ operationId: 'image:one', chatId: '-100', prompt: 'sleepy cat' });
    assert.equal((await media.reconcile('image:one')).state, 'succeeded');
    assert.deepEqual(store.outgoing(), [], 'simulate termination after result persistence but before queueing');
    assert.equal(budget.balance().spentMicros, 30);
    db.close(); db = new DatabaseSync(path);
    const restartedStore = new Store(db), restartedBudget = new Budget(db, 100);
    const restartedMedia = new Media(db, restartedBudget, { ...options, fetch: fetcher });
    assert.equal(queueCompletedMedia(restartedMedia, restartedStore), 1);
    assert.deepEqual(restartedStore.outgoing().map(row => ({ ...row, payload: JSON.parse(row.payload) })), [{ id: 'media-result:image:one', chat: '-100', method: 'sendPhoto', payload: {
      photo: 'https://fal.media/offline-cat.png', message_thread_id: 17,
      reply_parameters: { message_id: 42, allow_sending_without_reply: true }, chat_id: '-100',
    } }]);
    assert.equal(queueCompletedMedia(restartedMedia, restartedStore), 0);
    restartedStore.outgoingStatus('media-result:image:one', 'sending');
    restartedStore.recoverInterrupted();
    assert.equal(queueCompletedMedia(restartedMedia, restartedStore), 0, 'an ambiguous Telegram send must never be reconstructed as pending');
    assert.deepEqual(restartedStore.outgoing(), []);
    assert.equal(db.prepare('SELECT status FROM outgoing WHERE id=?').get('media-result:image:one')!.status, 'unknown');
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM outgoing').get()!.n, 1);
    assert.equal(requests, 3, 'delivery recovery must not resubmit or poll a completed paid job');
    assert.equal(restartedBudget.balance().spentMicros, 30);
  } finally { db.close(); await rm(directory, { recursive: true, force: true }); }
});

test('definitely rejected media queues one failure notice and does not duplicate an unknown delivery', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db), budget = new Budget(db, 100);
    const media = new Media(db, budget, { ...options, fetch: (async () => json({}, 422)) as typeof fetch });
    store.set('route:rejected', JSON.stringify({ messageId: 9, topic: 3 }));
    assert.equal((await media.generate({ operationId: 'rejected', chatId: '-100', prompt: 'invalid request' })).state, 'failed');
    assert.equal(queueCompletedMedia(media, store), 1);
    const message = store.outgoing()[0]!;
    assert.equal(message.method, 'sendMessage');
    assert.deepEqual(JSON.parse(message.payload), { text: 'Генерация не завершилась. Можно попробовать другой запрос.', message_thread_id: 3,
      reply_parameters: { message_id: 9, allow_sending_without_reply: true }, chat_id: '-100' });
    store.outgoingStatus(message.id, 'unknown');
    assert.equal(queueCompletedMedia(media, store), 0);
    assert.deepEqual(store.outgoing(), []);
    assert.equal(budget.balance().spentMicros, 0);
    assert.equal(budget.balance().reservedMicros, 0);
  } finally { db.close(); }
});
