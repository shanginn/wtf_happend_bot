import { randomUUID } from 'node:crypto';
import { readConfig } from './config.js';
import { Store, openDatabase } from './store.js';
import { acquireOwner } from './main.js';
import { Budget } from './budget.js';
import { MemoryStore } from './memory.js';
import { Stars } from './stars.js';
import { Runtime } from './runtime.js';
import { Jev } from './attention.js';
import { SkillSandbox } from './skills.js';
import { join } from 'node:path';
import { createSearch } from './search-client.js';

// Deployment check uses no Telegram sending, generation or payment tools.
const config = readConfig(), db = openDatabase(config.dataDir), owner = acquireOwner(config.dataDir);
const budget = new Budget(db, config.budgetMicros), store = new Store(db);
const runtime = new Runtime({ directory: join(config.dataDir, 'pi'), provider: config.provider, model: config.model,
  budget, store, memory: new MemoryStore(db), stars: new Stars(db, { signingKey: 'preflight-does-not-send-invoices-0000', botId: 'preflight' }) });
try {
  const script = '.agents/skills/sum/scripts/sum.ts';
  const result = await new SkillSandbox().run({ 'AGENTS.md': 'Use sum.',
    '.agents/skills/sum/SKILL.md': '---\nname: sum\ndescription: Sum signed values\n---\nRun the helper.',
    [script]: 'return input.reduce((a, b) => a + b, 0);' }, script, [100, -20, 5]);
  if (result !== 85) throw new Error('Isolated skill check failed');
  console.log(JSON.stringify({ phase: 'isolated-skill', ok: true }));
  const search = createSearch(config, budget, store);
  if (search) {
    const found = await search.search({ query: 'официальная документация SearXNG', limit: 3 }, { id: `preflight:search:${randomUUID()}`, chat: 'preflight' });
    if (!found.results.length) throw new Error('Internet search returned no startup-check results');
    if (!found.results.some(result => new URL(result.url).hostname === 'docs.searxng.org')) throw new Error('Ordinary search did not return the requested official documentation');
    console.log(JSON.stringify({ phase: 'internet-search', ok: true, provider: config.searchProvider, results: found.results.length }));
    const news = await search.search({ query: 'последние новости России сегодня', time_range: 'day', categories: 'news', limit: 5 }, { id: `preflight:news:${randomUUID()}`, chat: 'preflight' });
    if (!news.results.length) throw new Error('Russian news search returned no sources');
    console.log(JSON.stringify({ phase: 'russian-news-search', ok: true, results: news.results.length, domains: news.results.map(result => new URL(result.url).hostname), filtered: news.time_range_applied }));
  }
  const decision = await new Jev(config.jevKey, config.jevModel, budget, fetch, config.jevProvider).decide({
    eventId: `preflight:${randomUUID()}`, chatId: 'preflight', explicit: true, recent: [{ text: '@wtf_happend_bot привет, ответь пожалуйста' }],
    botName: 'wtf_happend_bot', now: Date.now() });
  if (decision.fallback) throw new Error('Jev check fell back');
  console.log(JSON.stringify({ phase: 'jev', ok: true, model: decision.model }));
  const operation = `preflight:model:${randomUUID()}`, allowance = runtime.quote();
  budget.reserve(operation, 'preflight', allowance);
  const probe = await runtime.probe('Изолированная проверка запуска. Ответь ровно: Готов к работе.', allowance);
  budget.settle(operation, probe.costMicros);
  if (!probe.answer) throw new Error('Model returned no answer');
  console.log(JSON.stringify({ phase: 'model', ok: true, provider: config.provider, model: config.model, answer: probe.answer, budget: budget.balance() }));
  if (store.outgoing().length) throw new Error('Preflight unexpectedly queued an outgoing action');
} catch { console.error('Deployment preflight failed; no Telegram message was sent'); process.exitCode = 1; }
finally { await runtime.close(); db.close(); owner.close(); }
