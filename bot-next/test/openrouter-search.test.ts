import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { Budget } from '../src/budget.js';
import { Store } from '../src/store.js';
import { OpenRouterSearch } from '../src/openrouter-search.js';

const model = 'deepseek/deepseek-v4.1-flash', operation = { id: 'search:test:1', chat: '-100' };
const providerResult = () => ({ usage: { cost: 0.008, server_tool_use_details: { web_search_requests: 1 } }, choices: [{ message: {
  content: 'Invented link https://invented.example/fake must never become a search result', annotations: [
    { type: 'url_citation', url_citation: { url: 'https://example.org/source', title: 'Source', content: 'Actual retrieved excerpt' } },
    { type: 'url_citation', url_citation: { url: 'https://example.org/source', title: 'Duplicate' } },
    { type: 'url_citation', url_citation: { url: 'https://user:secret@example.org/private' } },
    { type: 'url_citation', url_citation: { url: 'javascript:alert(1)' } },
  ],
} }] });
function fixture(funds = 1_000_000) { const db = new DatabaseSync(':memory:'); return { db, store: new Store(db), budget: new Budget(db, funds) }; }

test('OpenRouter search caps paid work, uses only real citations and accounts the complete provider cost once', async () => {
  const f = fixture(); let requests = 0;
  try {
    const search = new OpenRouterSearch(f.store, f.budget, 'private-test-key', model, async (url, init) => {
      requests++; assert.equal(url, 'https://openrouter.ai/api/v1/chat/completions');
      const body = JSON.parse(init!.body as string);
      assert.equal(body.model, model); assert.equal(body.max_tokens, 256);
      assert.equal(body.tools[0].type, 'openrouter:web_search');
      assert.deepEqual(body.tools[0].parameters, { engine: 'exa', mode: 'fast', max_results: 5, max_total_results: 5, max_uses: 1, max_characters: 1500 });
      assert.ok(f.budget.balance().reservedMicros > 7_000, 'reserve both search and inference before calling the provider');
      assert.equal(JSON.parse(body.messages[1].content).query, 'новости России');
      return Response.json(providerResult());
    });
    const result = await search.search({ query: 'новости России', time_range: 'day' }, operation);
    assert.equal(result.results.length, 1); assert.equal(result.results[0]!.content, 'Actual retrieved excerpt');
    assert.equal(result.time_range_applied, false, 'a natural-language recency request is not a confirmed hard date filter');
    assert.ok(result.warnings.some(warning => warning.includes('фильтр дат')));
    assert.ok(!JSON.stringify(result).includes('invented.example'));
    const restarted = new OpenRouterSearch(new Store(f.db), f.budget, 'private-test-key', model, async () => { throw new Error('must not repeat'); });
    assert.deepEqual(await restarted.search({ query: 'новости России', time_range: 'day' }, operation), result);
    assert.equal(requests, 1); assert.equal(f.budget.balance().spentMicros, 8_000); assert.equal(f.budget.balance().reservedMicros, 0);
    await assert.rejects(restarted.search({ query: 'changed' }, operation), /different arguments/);
    await assert.rejects(restarted.search({ query: 'новости России', time_range: 'day' }, { ...operation, chat: '-200' }), /different arguments/);
  } finally { f.db.close(); }
});
test('paid search refuses insufficient funds and missing operation IDs before network access', async () => {
  const f = fixture(1); let requests = 0;
  try {
    const search = new OpenRouterSearch(f.store, f.budget, 'private-key', model, async () => { requests++; return Response.json(providerResult()); });
    await assert.rejects(search.search({ query: 'test' }), /durable operation/);
    await assert.rejects(search.search({ query: 'test' }, operation), /Budget exhausted/);
    assert.equal(requests, 0);
  } finally { f.db.close(); }
});
test('unknown paid requests retain their reservation and cannot execute again after reopening', async () => {
  const f = fixture(); let requests = 0;
  try {
    const search = new OpenRouterSearch(f.store, f.budget, 'private-key', model, async () => { requests++; throw new Error('disconnected after search'); });
    await assert.rejects(search.search({ query: 'test' }, operation), /Do not repeat/);
    const reserved = f.budget.balance().reservedMicros; assert.ok(reserved > 7_000);
    const restarted = new OpenRouterSearch(f.store, f.budget, 'private-key', model, async () => { requests++; return Response.json(providerResult()); });
    await assert.rejects(restarted.search({ query: 'test' }, operation), /unknown/);
    assert.equal(requests, 1); assert.equal(f.budget.balance().reservedMicros, reserved);
  } finally { f.db.close(); }
});
test('unverified search or missing cost never turns model-written links into trusted results', async () => {
  for (const usage of [{}, { cost: 0.001, server_tool_use_details: { web_search_requests: 0 } }]) {
    const f = fixture();
    try {
      const search = new OpenRouterSearch(f.store, f.budget, 'private-key', model, async () => Response.json({ ...providerResult(), usage }));
      await assert.rejects(search.search({ query: 'test' }, operation), /could not be confirmed/);
      assert.ok(f.budget.balance().reservedMicros > 7_000);
    } finally { f.db.close(); }
  }
});
test('a reported amount below the fixed search price is conservatively charged with the search fee', async () => {
  const f = fixture();
  try {
    const payload = providerResult(); payload.usage.cost = 0.0002;
    const search = new OpenRouterSearch(f.store, f.budget, 'private-key', model, async () => Response.json(payload));
    await search.search({ query: 'test' }, operation);
    assert.equal(f.budget.balance().spentMicros, 7_200);
  } finally { f.db.close(); }
});
