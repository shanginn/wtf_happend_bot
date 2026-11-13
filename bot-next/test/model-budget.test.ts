import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { createModels, type Models } from '@earendil-works/pi-ai/models';
import { fauxProvider, fauxAssistantMessage } from '@earendil-works/pi-ai/providers/faux';
import { AssistantMessageEventStream } from '@earendil-works/pi-ai/utils/event-stream';
import type { AssistantMessage, SimpleStreamOptions } from '@earendil-works/pi-ai';
import { BACKGROUND_CONTEXT } from '@earendil-works/chord/context';
import { Harness, MemoryStorage, createRegistry } from '@earendil-works/pi-durable';
import { Budget } from '../src/budget.js';
import { budgetedModels, modelQuote, inputCeiling } from '../src/model-budget.js';

function modelsFixture() {
  const provider = fauxProvider({ provider: 'offline', api: 'offline', models: [{ id: 'test', contextWindow: 128_000, maxTokens: 4096, cost: { input: 0.1, output: 0.2, cacheRead: 0.01, cacheWrite: 0.1 } }] });
  const models = createModels(); models.setProvider(provider.provider);
  return { provider, models, model: provider.getModel() };
}
function completed(message: AssistantMessage): AssistantMessageEventStream {
  const stream = new AssistantMessageEventStream();
  stream.push({ type: 'start', partial: message });
  if (message.stopReason === 'error' || message.stopReason === 'aborted') stream.push({ type: 'error', reason: message.stopReason, error: message });
  else if (message.stopReason !== 'pending') stream.push({ type: 'done', reason: message.stopReason, message });
  stream.end(message);
  return stream;
}

test('insufficient model budget prevents any upstream call', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const f = modelsFixture(); f.provider.setResponses([fauxAssistantMessage('must not run')]);
    const wrapped = budgetedModels(f.models, new Budget(db, 0), 'chat');
    const result = await wrapped.completeSimple(f.model, { messages: [] });
    assert.equal(result.stopReason, 'error');
    assert.equal(f.provider.state.callCount, 0);
  } finally { db.close(); }
});

test('text-only models reject image input before upstream calls or reservations instead of silently dropping pixels', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const f = modelsFixture(), budget = new Budget(db, 1_000_000);
    const result = await budgetedModels(f.models, budget, 'chat').completeSimple({ ...f.model, input: ['text'] }, { messages: [{ role: 'user', timestamp: 0, content: [{ type: 'image', mimeType: 'image/png', data: 'synthetic' }] }] });
    assert.equal(result.stopReason, 'error'); assert.match(result.errorMessage!, /does not support image/);
    assert.equal(f.provider.state.callCount, 0); assert.equal(budget.balance().reservedMicros, 0);
  } finally { db.close(); }
});

test('all standard model entrypoints reserve and settle actual reported usage', async () => {
  for (const method of ['streamSimple', 'completeSimple', 'stream', 'complete'] as const) {
    const db = new DatabaseSync(':memory:');
    try {
      const f = modelsFixture(), budget = new Budget(db, 1_000_000);
      let calls = 0; let observed: SimpleStreamOptions | undefined;
      const message = fauxAssistantMessage('ok');
      message.usage = { input: 10, output: 5, cacheRead: 0, cacheWrite: 0, totalTokens: 15, cost: { input: 0.000002, output: 0.000003, cacheRead: 0, cacheWrite: 0, total: 0.000005 } };
      const source = new Proxy(f.models, { get(target, key) {
        if (key === 'stream' || key === 'streamSimple') return (_model: unknown, _context: unknown, options: SimpleStreamOptions) => { calls++; observed = options; return completed(message); };
        const value: unknown = Reflect.get(target, key); return typeof value === 'function' ? value.bind(target) : value;
      } }) as Models;
      const wrapped = budgetedModels(source, budget, 'chat');
      const result = method === 'stream' || method === 'streamSimple'
        ? await wrapped[method](f.model, { messages: [] }).result()
        : await wrapped[method](f.model, { messages: [] });
      assert.equal(result.stopReason, 'stop');
      assert.equal(calls, 1);
      assert.equal(observed?.maxTokens, 4096);
      assert.equal(observed?.maxRetries, 0);
      assert.equal(budget.balance().spentMicros, 5);
      assert.equal(budget.balance().reservedMicros, 0);
    } finally { db.close(); }
  }
});

test('stream failure preserves its maximum reservation despite zero usage', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const f = modelsFixture(), budget = new Budget(db, 1_000_000);
    f.provider.setResponses([fauxAssistantMessage('', { stopReason: 'error', errorMessage: 'connection lost' })]);
    const result = await budgetedModels(f.models, budget, 'chat').completeSimple(f.model, { messages: [] });
    assert.equal(result.stopReason, 'error');
    assert.equal(budget.balance().spentMicros, 0);
    assert.equal(budget.balance().reservedMicros, modelQuote(f.model, 4096, inputCeiling({ messages: [] })));
  } finally { db.close(); }
});

test('model quote covers higher context tiers and long cache-write rates', () => {
  const f = modelsFixture();
  const model = { ...f.model, contextWindow: 100_000, maxTokens: 4096, cost: { input: 1, output: 1, cacheRead: 0, cacheWrite: 0,
    tiers: [{ inputTokensAbove: 10_000, input: 10, output: 20, cacheRead: 2, cacheWrite: 0 }] } };
  assert.ok(modelQuote(model) >= 100_000 * 20 + 4096 * 20);
});

test('short text reserves a bounded input amount while image requests reserve the entire model window', () => {
  const f = modelsFixture();
  const short = { messages: [{ role: 'user' as const, content: 'Привет 👋', timestamp: 0 }] };
  assert.ok(inputCeiling(short) >= Buffer.byteLength(JSON.stringify(short)));
  assert.ok(modelQuote(f.model, 4096, inputCeiling(short)) < modelQuote(f.model));
  const image = { messages: [{ role: 'user' as const, content: [{ type: 'image' as const, data: 'ZmFrZQ==', mimeType: 'image/jpeg' }], timestamp: 0 }] };
  assert.equal(inputCeiling(image), Infinity);
  assert.equal(modelQuote(f.model, 4096, inputCeiling(image)), modelQuote(f.model));
});

test('manual compaction in official Pi goes through the same model budget gate', { timeout: 10_000 }, async () => {
  const db = new DatabaseSync(':memory:');
  const f = modelsFixture(), budget = new Budget(db, 1_000_000);
  const harness = await Harness.open(new MemoryStorage(), {
    models: budgetedModels(f.models, budget, 'chat'), registry: createRegistry(),
    settings: { compaction: { enabled: false, keepRecentTokens: 0 }, retry: { maxRetries: 0 } },
  }, BACKGROUND_CONTEXT);
  try {
    const root = await harness.root(BACKGROUND_CONTEXT, { agent: { model: { provider: 'offline', modelId: 'test' } } });
    f.provider.setResponses([fauxAssistantMessage('First answer'), fauxAssistantMessage('Second answer')]);
    await (await root.submit({ type: 'input', content: 'First prompt' }, BACKGROUND_CONTEXT)).wait(BACKGROUND_CONTEXT);
    await (await root.submit({ type: 'input', content: 'Second prompt' }, BACKGROUND_CONTEXT)).wait(BACKGROUND_CONTEXT);
    assert.equal(f.provider.state.callCount, 2);
    budget.reserve('block-everything', 'chat', budget.balance().availableMicros);
    const task = await root.compact('Summarize', BACKGROUND_CONTEXT);
    const outcome = await harness.waitForTask(task, BACKGROUND_CONTEXT);
    assert.equal(outcome.state.outcome.status, 'failed', JSON.stringify(outcome.state));
    assert.equal(f.provider.state.callCount, 2, 'compaction must not bypass global spend limit');
  } finally { await harness.close(BACKGROUND_CONTEXT); db.close(); }
});
