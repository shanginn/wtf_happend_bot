import test from 'node:test';
import assert from 'node:assert/strict';
import { InternetSearch } from '../src/search.js';

test('SearXNG query preserves filters and returns bounded, deduplicated source URLs and dates', async () => {
  const search = new InternetSearch('http://searxng:8080/search/', async (input, init) => {
    const url = new URL(String(input));
    assert.equal(url.pathname, '/search');
    assert.deepEqual(Object.fromEntries(url.searchParams), { q: 'новости TypeScript', format: 'json', safesearch: '0', categories: 'news', language: 'ru', time_range: 'day' });
    assert.equal(init?.redirect, 'error');
    return Response.json({ results: [
      { title: '<b>Новая версия</b>', url: 'https://example.org/news', content: 'Подробности &amp; <i>изменения</i>', publishedDate: '2026-11-10', engines: ['google', 'bing'] },
      { title: 'duplicate', url: 'https://example.org/news' },
      { title: 'invalid', url: 'javascript:alert(1)' },
      { title: 'secret', url: 'https://user:password@example.org/' },
      { title: 'Second', url: 'https://example.org/2', snippet: 'x'.repeat(5000), engine: 'bing' },
      { title: 'Third', url: 'https://example.org/3' },
    ], answers: ['Answer'], corrections: ['Correction'], suggestions: ['Related'] });
  });
  const result = await search.search({ query: ' новости TypeScript ', limit: 2, safe_search: 0, categories: 'news', language: 'ru', time_range: 'day' });
  assert.equal(result.results.length, 2);
  assert.deepEqual(result.results[0], { title: 'Новая версия', url: 'https://example.org/news', content: 'Подробности & изменения', engines: ['google', 'bing'], published_at: '2026-11-10' });
  assert.equal(result.results[1]!.content.length, 1200);
  assert.deepEqual(result.results[1]!.engines, ['bing']);
  assert.deepEqual(result.answers, ['Answer']);
  assert.deepEqual(result.suggestions, ['Related']);
});

test('empty search results retain upstream engine failures rather than inventing an answer', async () => {
  const search = new InternetSearch('https://search.example.org', async input => {
    const url = new URL(String(input));
    assert.equal(url.pathname, '/search');
    assert.equal(url.searchParams.has('language'), false);
    assert.equal(url.searchParams.get('categories'), 'general');
    return Response.json({ results: [], unresponsive_engines: [['duckduckgo', 'CAPTCHA'], ['brave', 'too many requests']] });
  });
  const result = await search.search({ query: 'unknown query', language: 'auto' });
  assert.deepEqual(result.results, []);
  assert.deepEqual(result.answers, []);
  assert.deepEqual(result.warnings, ['duckduckgo: CAPTCHA', 'brave: too many requests']);
});

test('configured working engines are sent explicitly and the tool can choose other engines', async () => {
  const engines: (string | null)[] = [];
  const search = new InternetSearch('http://searxng:8080', async input => {
    engines.push(new URL(String(input)).searchParams.get('engines'));
    return Response.json({ results: [] });
  }, 'google,bing,wikipedia');
  await search.search({ query: 'test' });
  await search.search({ query: 'test', engines: 'google news,bing news', categories: 'news' });
  assert.deepEqual(engines, ['google,bing,wikipedia', 'google news,bing news']);
});

test('search reports HTTP, malformed responses and network errors without transport secrets', async () => {
  for (const [response, expected] of [
    [new Response('Forbidden', { status: 403 }), /HTTP 403.*JSON/],
    [new Response('<html>Error</html>'), /invalid JSON/],
    [Response.json({ error: 'search failed' }), /invalid JSON/],
    [Response.json(null), /invalid JSON/],
  ] as const) await assert.rejects(new InternetSearch('http://searxng:8080', async () => response).search({ query: 'test' }), expected);
  await assert.rejects(new InternetSearch('https://search.example.org', async () => { throw new Error('private transport details'); }).search({ query: 'test' }), error => error instanceof Error && error.message.includes('temporarily unavailable') && !error.message.includes('private transport'));
});

test('search validates query before network access and rejects non-HTTP or credential-bearing endpoints', async () => {
  let calls = 0;
  const search = new InternetSearch('http://searxng:8080', async () => { calls++; return Response.json({ results: [] }); });
  await assert.rejects(search.search({ query: '  ' }), /1–1000/);
  await assert.rejects(search.search({ query: 'x'.repeat(1001) }), /1–1000/);
  assert.equal(calls, 0);
  assert.throws(() => new InternetSearch('file:///etc/passwd'), /Invalid SEARCH_BASE_URL/);
  assert.throws(() => new InternetSearch('https://user:password@search.example.org'), /Invalid SEARCH_BASE_URL/);
});

test('oversized search responses are cancelled while streaming', async () => {
  let cancelled = false;
  const search = new InternetSearch('http://searxng:8080', async () => new Response(new ReadableStream({
    pull(controller) { controller.enqueue(new Uint8Array(1_000_001)); }, cancel() { cancelled = true; },
  })));
  await assert.rejects(search.search({ query: 'test' }), /too large/);
  assert.equal(cancelled, true);
});
