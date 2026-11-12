import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { createHash } from 'node:crypto';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createModels } from '@earendil-works/pi-ai/models';
import { fauxProvider, fauxAssistantMessage, fauxToolCall } from '@earendil-works/pi-ai/providers/faux';
import { Budget } from '../src/budget.js';
import { MemoryStore } from '../src/memory.js';
import { Store } from '../src/store.js';
import { Stars } from '../src/stars.js';
import { Runtime } from '../src/runtime.js';
import { Telegram } from '../src/telegram.js';
import { InternetSearch, type SearchClient } from '../src/search.js';
import { OpenRouterSearch } from '../src/openrouter-search.js';
import { buildTelegramInput } from '../src/telegram-context.js';
import { Widgets } from '../src/widgets.js';
import { messageWidget } from '../src/widget-state.js';

async function fixture() {
  const directory = await mkdtemp(join(tmpdir(), 'wtf-pi-runtime-test-'));
  const db = new DatabaseSync(':memory:');
  const provider = fauxProvider({ provider: 'offline', api: 'offline', models: [{ id: 'test', contextWindow: 128_000, maxTokens: 4096, cost: { input: 0.1, output: 0.2, cacheRead: 0.01, cacheWrite: 0.1 } }] });
  const models = createModels(); models.setProvider(provider.provider);
  const memory = new MemoryStore(db), store = new Store(db), budget = new Budget(db, 10_000_000);
  const stars = new Stars(db, { signingKey: 'x'.repeat(32), botId: 'offline' });
  const options = { directory, provider: 'offline', model: 'test', models, memory, store, budget, stars };
  const runtimes: Runtime[] = [];
  return { directory, provider, memory, store, budget, create(telegram?: Telegram, search?: SearchClient) { const runtime = new Runtime({ ...options, telegram, search }); runtimes.push(runtime); return runtime; },
    async close() { for (const runtime of runtimes) await runtime.close(); db.close(); await rm(directory, { recursive: true, force: true }); } };
}

test('official Pi Durable responds and reuses persisted root and submission after restart', { timeout: 10_000 }, async () => {
  const f = await fixture();
  try {
    f.provider.setResponses([fauxAssistantMessage('Привет, чат!')]);
    const first = f.create();
    assert.equal(await first.respond('-100', 'update:1', 'Привет'), 'Привет, чат!');
    assert.equal(f.provider.state.callCount, 1);
    await first.close();
    const restarted = f.create();
    assert.equal(await restarted.respond('-100', 'update:1', 'Привет'), 'Привет, чат!');
    assert.equal(f.provider.state.callCount, 1, 'replaying an admitted input must not call the provider');
    f.provider.appendResponses([fauxAssistantMessage('Другой чат')]);
    assert.equal(await restarted.respond('-200', 'update:1', 'Привет'), 'Другой чат');
    assert.equal(f.provider.state.callCount, 2);
  } finally { await f.close(); }
});

test('legacy Telegram message blocks and participant/timestamp metadata survive Pi persistence and replay', { timeout: 10_000 }, async () => {
  const f = await fixture();
  try {
    const message = { message_id: 42, date: 1794381617, chat: { id: -100, type: 'supergroup' }, from: { id: 7, first_name: 'Ник', is_bot: false }, text: 'Кто это написал?' };
    const content = buildTelegramInput({ current: { message, updateId: 100, kind: 'message' }, history: [], timezone: 'Asia/Yekaterinburg', bot: { id: 777, first_name: 'Bot', is_bot: true }, now: 1794381620000, memories: [] });
    const entry = { kind: 'telegram.message', data: { telegramParticipant: 'telegram_user:7', telegramMessageTimestamp: message.date, telegramUpdateId: 100, telegramMessageId: 42 } };
    f.provider.setResponses([context => {
      const input = context.messages.find(message => message.role === 'user');
      assert.deepEqual(input?.content, content);
      return fauxAssistantMessage('Ник.');
    }]);
    const first = f.create();
    assert.equal(await first.respond('-100', 'telegram:100', content, message.text, entry), 'Ник.');
    await first.close();
    const db = new DatabaseSync(join(f.directory, `${createHash('sha256').update('-100').digest('hex')}.sqlite`), { readOnly: true });
    try {
      const rows = db.prepare("SELECT record FROM entries WHERE json_extract(record, '$.kind')='telegram.message'").all() as { record: string }[];
      assert.equal(rows.length, 1);
      const persisted = JSON.parse(rows[0]!.record);
      assert.deepEqual(persisted.data, entry.data);
      assert.deepEqual(persisted.model[0].content, content);
    } finally { db.close(); }
    assert.equal(await f.create().respond('-100', 'telegram:100', content, message.text, entry), 'Ник.');
    assert.equal(f.provider.state.callCount, 1);
  } finally { await f.close(); }
});

test('reply drafts never send HTTP and a different complete final is preserved through restart', { timeout: 10_000 }, async () => {
  const f = await fixture(); let sends = 0, draftReceipt: Record<string, unknown> | undefined;
  const telegram = new Telegram('fixture-token', async (_url, init) => {
    sends++; return Response.json({ ok: true, result: { message_id: 42, chat: { id: -100 }, text: JSON.parse(init!.body as string).text } });
  });
  try {
    const runtime = f.create(telegram), delivery = runtime.replies.open('-100', 'telegram:1', { chat: '-100', messageId: 1 });
    const entry = { kind: 'telegram.message', data: { telegramUpdateId: 1, replyProtocol: { version: 1, id: delivery.id } } };
    const final = 'Полный финальный ответ. Важное дополнение, которого не было в черновике.';
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendMessage', parameters: { chat_id: '-100', text: 'Предварительный ответ.' } }), { stopReason: 'toolUse' }),
      context => {
        const results = context.messages.filter(message => message.role === 'toolResult');
        const block = results.at(-1)!.content.find(item => item.type === 'text');
        if (block?.type === 'text') draftReceipt = JSON.parse(block.text);
        return fauxAssistantMessage(final);
      },
    ]);
    const answer = await runtime.respond('-100', 'telegram:1', 'Ответь.', 'Ответь.', entry);
    assert.equal(draftReceipt?.delivery, 'draft', JSON.stringify(draftReceipt)); assert.equal(draftReceipt?.sent, false);
    assert.equal(answer, final); assert.equal(sends, 0);
    runtime.replies.complete(delivery, answer);
    assert.equal(f.store.outgoing().length, 1); assert.equal(JSON.parse(f.store.outgoing()[0]!.payload).text, final);
    const modelCalls = f.provider.state.callCount; await runtime.close();
    const restarted = f.create(telegram);
    assert.equal(await restarted.respond('-100', 'telegram:1', 'Ответь.', 'Ответь.', entry), final);
    restarted.replies.complete(delivery, final);
    assert.equal(f.store.outgoing().length, 1); assert.equal(f.provider.state.callCount, modelCalls);
    const out = f.store.outgoing()[0]!; assert.equal(f.store.claimOutgoing(out.id), true);
    f.store.outgoingStatus(out.id, 'sent', await telegram.call(out.method, JSON.parse(out.payload)));
    restarted.replies.complete(delivery, final); assert.equal(sends, 1); assert.equal(f.store.outgoing().length, 0);
  } finally { await f.close(); }
});

test('a failure after a reply draft creates one failure delivery rather than sending the draft', { timeout: 10_000 }, async () => {
  const f = await fixture(); let requests = 0;
  const telegram = new Telegram('fixture-token', async () => { requests++; throw new Error('Must not dispatch a draft'); });
  try {
    const runtime = f.create(telegram), delivery = runtime.replies.open('-100', 'failed:1', { chat: '-100', messageId: 1 });
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendMessage', parameters: { chat_id: '-100', text: 'Черновик.' } }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('', { stopReason: 'error', errorMessage: 'Provider offline' }),
    ]);
    await assert.rejects(runtime.respond('-100', 'failed:1', 'Ответь.', 'Ответь.', { kind: 'telegram.message', data: { replyProtocol: { version: 1, id: delivery.id } } }), /Pi run did not finish/);
    assert.equal(requests, 0); assert.equal(f.store.outgoing().length, 0);
    runtime.replies.fail(delivery, 'Не получилось завершить ответ.');
    assert.equal(f.store.outgoing().length, 1); assert.equal(JSON.parse(f.store.outgoing()[0]!.payload).text, 'Не получилось завершить ответ.');
  } finally { await f.close(); }
});

test('concurrent inputs in one Pi session retain distinct durable reply contexts', { timeout: 10_000 }, async () => {
  const f = await fixture(); let requests = 0;
  const telegram = new Telegram('fixture-token', async () => { requests++; throw new Error('Draft HTTP'); });
  try {
    const runtime = f.create(telegram);
    const deliveries = [1, 2].map(id => runtime.replies.open('-100', `turn:${id}`, { chat: '-100', messageId: id }));
    f.provider.setResponses([1, 2].flatMap(id => [
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendMessage', parameters: { chat_id: '-100', text: `Draft ${id}` } }), { stopReason: 'toolUse' }),
      fauxAssistantMessage(`Final ${id}`),
    ]));
    const answers = await Promise.all(deliveries.map((delivery, i) => runtime.respond('-100', `turn:${i + 1}`, `Request ${i + 1}`, undefined, { kind: 'telegram.message', data: { replyProtocol: { version: 1, id: delivery.id } } })));
    assert.deepEqual(answers, ['Final 1', 'Final 2']); assert.equal(requests, 0);
    assert.deepEqual(new Set(f.store.db.prepare('SELECT reply_id FROM reply_drafts').all().map(row => row.reply_id)), new Set(deliveries.map(delivery => delivery.id)));
    deliveries.forEach((delivery, i) => runtime.replies.complete(delivery, answers[i]!)); assert.equal(f.store.outgoing().length, 2);
  } finally { await f.close(); }
});

test('explicit literal API actions are independent of the complete conversational final and replay once', { timeout: 10_000 }, async () => {
  const f = await fixture(); const sent: string[] = [];
  const telegram = new Telegram('fixture-token', async (_url, init) => {
    const args = JSON.parse(init!.body as string); sent.push(args.text);
    return Response.json({ ok: true, result: { message_id: sent.length, chat: { id: -100 }, text: args.text } });
  });
  try {
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('telegram_api', { purpose: 'action', method: 'sendMessage', parameters: { chat_id: '-100', text: 'Первое отдельное сообщение.' } }), { stopReason: 'toolUse' }),
      fauxAssistantMessage(fauxToolCall('telegram_api', { purpose: 'action', method: 'sendMessage', parameters: { chat_id: '-100', text: 'Второе отдельное сообщение.' } }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('Самостоятельный финал с важным уточнением.'),
    ]);
    const runtime = f.create(telegram), final = await runtime.respond('-100', 'actions:1', 'Отправь два сообщения и поясни результат.');
    assert.equal(final, 'Самостоятельный финал с важным уточнением.'); assert.equal(sent.length, 2);
    await runtime.close(); assert.equal(await f.create(telegram).respond('-100', 'actions:1', 'Отправь два сообщения и поясни результат.'), final);
    assert.equal(sent.length, 2);
  } finally { await f.close(); }
});

test('missing or internal reply context never falls back to a literal sendMessage', { timeout: 10_000 }, async () => {
  const f = await fixture(); let requests = 0;
  const telegram = new Telegram('fixture-token', async () => { requests++; throw new Error('Unexpected send'); });
  try {
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendMessage', parameters: { chat_id: '-100', text: 'Draft' } }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('Полный финал.'),
    ]);
    assert.equal(await f.create(telegram).respond('-100', 'internal:1', 'Request'), 'Полный финал.'); assert.equal(requests, 0);
  } finally { await f.close(); }
});

test('Chinese output is repaired with zero tools, without repeating Telegram effects, and survives replay', { timeout: 10_000 }, async () => {
  const f = await fixture();
  try {
    let sends = 0;
    const telegram = new Telegram('private-token', async () => { sends++; return Response.json({ ok: true, result: { message_id: 42 } }); });
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendPoll', parameters: { chat_id: '-100', question: 'Восстановить память?', options: [{ text: 'Да' }, { text: 'Нет' }] } }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('投票已经创建了。现在大家可以选择是否恢复记忆，我不会再次重复发送这个投票。'),
      context => {
        assert.equal(context.messages.flatMap(message => message.role === 'system' ? message.toolsAdded ?? [] : []).length, 0);
        assert.ok(!JSON.stringify(context).includes('sendPoll'), 'the language editor receives only the answer, without action history');
        return fauxAssistantMessage('Опрос создан.');
      },
    ]);
    const first = f.create(telegram);
    assert.equal(await first.respond('-100', 'reply:language:1', 'Создай опрос о восстановлении памяти.'), 'Опрос создан.');
    assert.equal(sends, 1);
    assert.equal(f.provider.state.callCount, 3);
    await first.close();
    assert.equal(await f.create(telegram).respond('-100', 'reply:language:1', 'Создай опрос о восстановлении памяти.'), 'Опрос создан.');
    assert.equal(sends, 1);
    assert.equal(f.provider.state.callCount, 3, 'replaying the repaired answer must not call either model again');
  } finally { await f.close(); }
});

test('language correction cannot leak another Chinese reply while requested translations remain intact', { timeout: 10_000 }, async () => {
  const f = await fixture();
  const chinese = '搜索引擎现在都被挡住了，一个结果都没返回，所以我不会编造新闻。';
  try {
    f.provider.setResponses([fauxAssistantMessage(chinese), fauxAssistantMessage(chinese)]);
    await assert.rejects(f.create().respond('-100', 'bad:language', 'Какие новости?'), /wrong language/);
    f.provider.appendResponses([fauxAssistantMessage(chinese)]);
    assert.equal(await f.create().respond('-200', 'requested:language', 'Переведи на китайский'), chinese);
  } finally { await f.close(); }
});

test('persisted Pi root gains internet_search, receives source URLs and replays without another search', { timeout: 10_000 }, async () => {
  const f = await fixture();
  try {
    f.provider.setResponses([fauxAssistantMessage('Привет!')]);
    const old = f.create(); await old.respond('-100', 'before:search', 'Привет'); await old.close();
    let requests = 0;
    const search = new InternetSearch('http://searxng:8080', async input => {
      assert.equal(new URL(String(input)).searchParams.get('q'), 'TypeScript latest release'); requests++;
      return Response.json({ results: [{ title: 'Release notes', url: 'https://www.typescriptlang.org/docs/handbook/release-notes/overview.html', content: 'Official release notes', engine: 'google' }] });
    });
    f.provider.appendResponses([
      context => {
        assert.ok(context.messages.some(message => message.role === 'system' && message.toolsAdded?.some(tool => tool.name === 'internet_search')));
        return fauxAssistantMessage(fauxToolCall('internet_search', { query: 'TypeScript latest release', limit: 3 }), { stopReason: 'toolUse' });
      },
      context => {
        const result = context.messages.filter(message => message.role === 'toolResult').at(-1);
        assert.equal(result?.isError, false);
        assert.ok(JSON.stringify(result).includes('https://www.typescriptlang.org/docs/handbook/release-notes/overview.html'));
        return fauxAssistantMessage('Нашёл [официальные изменения](https://www.typescriptlang.org/docs/handbook/release-notes/overview.html).');
      },
    ]);
    const upgraded = f.create(undefined, search), answer = await upgraded.respond('-100', 'search:1', 'Поищи последнюю версию TypeScript');
    assert.ok(answer.includes('https://www.typescriptlang.org/')); await upgraded.close();
    assert.equal(await f.create(undefined, search).respond('-100', 'search:1', 'Поищи последнюю версию TypeScript'), answer);
    assert.equal(requests, 1);
  } finally { await f.close(); }
});

test('real Pi invokes paid search with a durable chat-bound ID and never charges twice on replay', { timeout: 10_000 }, async () => {
  const f = await fixture(); let requests = 0;
  try {
    const search = new OpenRouterSearch(f.store, f.budget, 'private-native-key', 'deepseek/deepseek-v4.1-flash', async () => {
      requests++; return Response.json({ usage: { cost: 0.008, server_tool_use_details: { web_search_requests: 1 } }, choices: [{ message: { annotations: [{ type: 'url_citation', url_citation: { url: 'https://example.org/news', title: 'Новость', content: 'Подтверждённая выдержка источника' } }] } }] });
    });
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('internet_search', { query: 'последние новости России' }), { stopReason: 'toolUse' }),
      context => {
        assert.ok(JSON.stringify(context).includes('Подтверждённая выдержка источника'));
        assert.ok(!JSON.stringify(context).includes('private-native-key'));
        return fauxAssistantMessage('Нашёл источник: https://example.org/news');
      },
    ]);
    const first = f.create(undefined, search), answer = await first.respond('-100', 'native:1', 'Найди российские новости');
    await first.close();
    assert.equal(await f.create(undefined, search).respond('-100', 'native:1', 'Найди российские новости'), answer);
    assert.equal(requests, 1);
    const charge = f.store.db.prepare("SELECT chat_id,actual_micros,state FROM budget_operations WHERE operation_id LIKE 'search:%'").get()!;
    assert.equal(charge.chat_id, '-100'); assert.equal(charge.actual_micros, 8_000); assert.equal(charge.state, 'settled');
  } finally { await f.close(); }
});

test('search diagnoses are checked against this submission only, excluding old CAPTCHA errors on restart', { timeout: 10_000 }, async () => {
  const f = await fixture(); let requests = 0;
  try {
    const search = new InternetSearch('http://searxng:8080', async input => {
      requests++; const url = new URL(String(input));
      if (url.searchParams.get('q')!.includes('old-query')) return Response.json({ results: [], unresponsive_engines: [['brave', 'CAPTCHA']] });
      if (url.searchParams.has('time_range')) return Response.json({ results: [], unresponsive_engines: [['bing news', 'parsing error']] });
      return Response.json({ results: Array.from({ length: 8 }, (_, n) => ({ title: `Источник ${n}`, url: `https://example.org/news/${n}`, engine: 'bing' })) });
    }, 'bing');
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('internet_search', { query: 'old-query' }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('Старый запрос не дал ссылок.'),
    ]);
    const first = f.create(undefined, search);
    await first.respond('-100', 'search:old', 'Старый запрос'); await first.close();
    f.provider.appendResponses([
      fauxAssistantMessage(fauxToolCall('internet_search', { query: 'главные новости', time_range: 'day', limit: 8 }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('По российским новостям выдача пустая — русские движки сейчас капчами и лимитами задушены.'),
      context => {
        assert.equal(context.messages.flatMap(message => message.role === 'system' ? message.toolsAdded ?? [] : []).length, 0);
        const input = context.messages.find(message => message.role === 'user');
        assert.ok(input);
        const text = typeof input.content === 'string' ? input.content : input.content.flatMap(item => item.type === 'text' ? [item.text] : []).join('\n');
        const data = JSON.parse(text) as { current_search_results: { query: string; results: unknown[]; warnings: string[] }[] };
        assert.equal(data.current_search_results.length, 1);
        assert.equal(data.current_search_results[0]!.query, 'главные новости');
        assert.equal(data.current_search_results[0]!.results.length, 8);
        assert.ok(!JSON.stringify(data.current_search_results).includes('CAPTCHA'), 'old failures must not enter the reviewer evidence');
        return fauxAssistantMessage('Нашёл 8 ссылок. Их свежесть за сутки не подтверждена. https://example.org/news/0');
      },
    ]);
    const upgraded = f.create(undefined, search);
    const answer = await upgraded.respond('-100', 'search:current', 'Найди российские новости');
    assert.ok(answer.includes('Нашёл 8 ссылок')); assert.ok(!answer.includes('капч'));
    const completedRequests = requests, completedCalls = f.provider.state.callCount; await upgraded.close();
    assert.equal(await f.create(undefined, search).respond('-100', 'search:current', 'Найди российские новости'), answer);
    assert.equal(requests, completedRequests); assert.equal(f.provider.state.callCount, completedCalls);
  } finally { await f.close(); }
});

test('a reviewer repeating an invented search outage is replaced with factual links without retrying actions', { timeout: 10_000 }, async () => {
  const f = await fixture(); let requests = 0;
  try {
    const search = new InternetSearch('http://searxng:8080', async () => { requests++; return Response.json({ results: [{ title: 'Источник', url: 'https://example.org/news' }] }); });
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('internet_search', { query: 'новости' }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('Поиск заблокирован CAPTCHA.'),
      fauxAssistantMessage('Поиск заблокирован CAPTCHA.'),
    ]);
    const answer = await f.create(undefined, search).respond('-100', 'search:still-wrong', 'Найди новости');
    assert.ok(answer.includes('https://example.org/news'));
    assert.ok(!answer.includes('CAPTCHA'));
    assert.equal(requests, 1);
  } finally { await f.close(); }
});

test('existing Pi root gains the universal Telegram API tool and creates a real poll through it', { timeout: 10_000 }, async () => {
  const f = await fixture();
  try {
    f.provider.setResponses([fauxAssistantMessage('Привет!')]);
    const old = f.create(); await old.respond('-100', 'before:upgrade', 'Привет'); await old.close();
    let requests = 0;
    const telegram = new Telegram('private-token', async (url, init) => {
      assert.equal(String(url).split('/').at(-1), 'sendPoll');
      assert.deepEqual(JSON.parse(init!.body as string), { chat_id: '-100', message_thread_id: 17, question: 'Восстанавливать память?', options: [{ text: 'Да' }, { text: 'Нет' }], is_anonymous: false });
      requests++;
      return Response.json({ ok: true, result: { message_id: 42, poll: { id: 'telegram-poll-id' } } });
    });
    f.provider.appendResponses([
      context => {
        const tool = context.messages.flatMap(message => message.role === 'system' ? message.toolsAdded ?? [] : []).find(tool => tool.name === 'telegram_api');
        assert.ok(tool, 'the persisted Pi root must see the new tool on restart');
        assert.ok(tool.description.includes('-100'));
        return fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendPoll', parameters: { chat_id: '-100', message_thread_id: 17, question: 'Восстанавливать память?', options: [{ text: 'Да' }, { text: 'Нет' }], is_anonymous: false } }), { stopReason: 'toolUse' });
      },
      context => {
        const results = context.messages.filter(m => m.role === 'toolResult');
        assert.equal(results.at(-1)?.isError, false);
        assert.ok(JSON.stringify(results).includes('telegram-poll-id'));
        assert.ok(!JSON.stringify(context).includes('private-token'));
        return fauxAssistantMessage('Опрос создан.');
      },
    ]);
    const upgraded = f.create(telegram);
    assert.equal(await upgraded.respond('-100', 'poll:1', 'Создай опрос, стоит ли восстанавливать память, в теме 17'), 'Опрос создан.');
    await upgraded.close();
    assert.equal(await f.create(telegram).respond('-100', 'poll:1', 'Создай опрос, стоит ли восстанавливать память, в теме 17'), 'Опрос создан.');
    assert.equal(requests, 1, 'replaying Pi input must not create a second poll');
  } finally { await f.close(); }
});

test('existing Pi roots generate checked HTML widgets; clicks use isolated durable sessions and never repeat confirmed edits', { timeout: 10_000 }, async () => {
  const f = await fixture(); let sends = 0, edits = 0, acknowledgements = 0;
  const html = '<button onclick="TelegramWidget.request(\'inc\')">+</button><b>Counter</b>';
  const telegram = new Telegram('fixture-token', async (url, init) => {
    const method = String(url).split('/').at(-1), parameters = JSON.parse(init!.body as string);
    if (method === 'sendWidget') { sends++; return Response.json({ ok: true, result: { message_id: 99, date: 1794381617, chat: { id: 10, type: 'private' }, widget: { html: parameters.html, chat_data: parameters.chat_data } } }); }
    if (method === 'editMessageWidgetData') { edits++; assert.equal(parameters.chat_data, '{"n":1}'); }
    if (method === 'answerWidgetQuery') acknowledgements++;
    return Response.json({ ok: true, result: true });
  });
  try {
    f.provider.setResponses([fauxAssistantMessage('Принял.')]);
    const old = f.create(); await old.respond('10', 'ordinary:1', 'ORDINARY_CHAT_PRIVATE_FIXTURE'); await old.close();
    f.provider.appendResponses([
      context => {
        const tools = context.messages.flatMap(message => message.role === 'system' ? message.toolsAdded ?? [] : []);
        assert.ok(tools.some(tool => tool.name === 'telegram_widget_check'));
        assert.ok(JSON.stringify(context).includes('Bot API 10.4'));
        return fauxAssistantMessage(fauxToolCall('telegram_widget_check', { html, chat_data: '{"n":0}' }), { stopReason: 'toolUse' });
      },
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendWidget', parameters: { chat_id: '10', html, chat_data: '{"n":0}', allow_queries: true } }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('Виджет создан.'),
    ]);
    const upgraded = f.create(telegram); assert.equal(await upgraded.respond('10', 'widget:create', 'Создай счётчик в HTML-виджете.'), 'Виджет создан.');
    await upgraded.close();
    const restarted = f.create(telegram);
    assert.equal(await restarted.respond('10', 'widget:create', 'Создай счётчик в HTML-виджете.'), 'Виджет создан.'); assert.equal(sends, 1);
    const widgets = new Widgets(f.store, telegram), query = { update_id: 51, widget_query: { id: 'click:1', from: { id: 10 }, chat: { id: 10, type: 'private' }, message_id: 99, data: 'inc', type: { type: 'data' } } };
    await widgets.accept(query); assert.equal(acknowledgements, 1);
    f.provider.appendResponses([
      context => {
        assert.ok(!JSON.stringify(context).includes('ORDINARY_CHAT_PRIVATE_FIXTURE'), 'widget interactions must not inherit ordinary chat history');
        return fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'editMessageWidgetData', parameters: { chat_id: '10', message_id: 99, chat_data: '{"n":1}' } }), { stopReason: 'toolUse' });
      },
      fauxAssistantMessage('Готово.'),
    ]);
    await widgets.process(widgets.pending()[0]!, restarted, 'UTC'); assert.equal(edits, 1);
    assert.equal(messageWidget(f.store, '10', 99)?.chatData, '{"n":1}');
    const modelCalls = f.provider.state.callCount; await restarted.close();
    // Simulate a crash after the edit/submission committed but before the queue completion was retained.
    f.store.db.prepare("UPDATE widget_inbox SET status='processing'").run();
    widgets.recoverInterrupted(); await widgets.accept(query);
    await widgets.process(widgets.pending()[0]!, f.create(telegram), 'UTC');
    assert.equal(f.store.db.prepare('SELECT status FROM widget_inbox').get()!.status, 'done');
    assert.equal(edits, 1); assert.equal(acknowledgements, 1); assert.equal(f.provider.state.callCount, modelCalls);
    assert.equal(f.store.outgoing().length, 0);
  } finally { await f.close(); }
});

test('different widget sessions cannot collide on Telegram or search operations with equal local Pi task IDs', { timeout: 10_000 }, async () => {
  const f = await fixture(); let telegramCalls = 0; const searchIds: string[] = [];
  const telegram = new Telegram('fixture-token', async () => { telegramCalls++; return Response.json({ ok: true, result: true }); });
  const source = new InternetSearch('http://fixture:8080', async () => Response.json({ results: [{ title: 'Fixture', url: 'https://example.org/' }] }));
  const search: SearchClient = { search: async (options, operation) => { searchIds.push(operation!.id); assert.equal(operation!.chat, '10'); return source.search(options); } };
  try {
    f.provider.setResponses([1, 2].flatMap(id => [
      fauxAssistantMessage(fauxToolCall('internet_search', { query: `widget ${id}` }), { stopReason: 'toolUse' }),
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'editMessageWidgetData', parameters: { chat_id: '10', message_id: id, chat_data: '{"n":1}' } }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('Готово.'),
    ]));
    const first = f.create(telegram, search);
    for (const id of [1, 2]) assert.equal(await first.respond('10', `click:${id}`, 'Обнови виджет', 'Обнови виджет', undefined, `widget:message:10:${id}`), 'Готово.');
    assert.equal(telegramCalls, 2);
    const rows = f.store.db.prepare("SELECT id FROM telegram_calls WHERE id LIKE 'telegram-api:%' ORDER BY id").all() as { id: string }[];
    assert.equal(rows.length, 2);
    assert.equal(rows[0]!.id.split(':').at(-1), rows[1]!.id.split(':').at(-1), 'the fixture must exercise equal task IDs in different Pi files');
    assert.equal(new Set(searchIds).size, 2); assert.equal(searchIds[0]!.split(':').at(-1), searchIds[1]!.split(':').at(-1));
    const modelCalls = f.provider.state.callCount; await first.close();
    const restarted = f.create(telegram, search);
    for (const id of [1, 2]) assert.equal(await restarted.respond('10', `click:${id}`, 'Обнови виджет', 'Обнови виджет', undefined, `widget:message:10:${id}`), 'Готово.');
    assert.equal(telegramCalls, 2); assert.equal(searchIds.length, 2); assert.equal(f.provider.state.callCount, modelCalls);
  } finally { await f.close(); }
});

test('real Pi tool calls remember and recall only trusted chat scope', { timeout: 10_000 }, async () => {
  const f = await fixture();
  try {
    f.memory.remember({ scope: '-200', subject: 'other', text: 'secret-other-chat', sources: [{ id: '-200:9', quote: 'secret-other-chat' }] });
    f.store.ingest({ update_id: 1, message: { message_id: 42, date: 1_700_000_000, chat: { id: -100 }, from: { id: 7 }, text: 'Я люблю чай.' } }, ['-100']);
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('remember', { subject: 'user:7', text: 'Любит чай', message_id: 42, quote: 'люблю чай' }), { stopReason: 'toolUse' }),
      fauxAssistantMessage(fauxToolCall('recall_memory', { query: '' }), { stopReason: 'toolUse' }),
      context => {
        const toolResults = context.messages.filter(m => m.role === 'toolResult');
        assert.ok(JSON.stringify(toolResults).includes('Любит чай'));
        assert.ok(!JSON.stringify(toolResults).includes('secret-other-chat'));
        return fauxAssistantMessage('Запомнил про чай.');
      },
    ]);
    assert.equal(await f.create().respond('-100', 'remember:1', 'Запомни, что я люблю чай'), 'Запомнил про чай.');
    assert.equal(f.memory.recall({ scope: '-100', query: '' }).length, 1);
    assert.equal(f.memory.recall({ scope: '-200', query: '' }).length, 1);
  } finally { await f.close(); }
});

test('real Pi cannot save a fact quoting a message from another chat', { timeout: 10_000 }, async () => {
  const f = await fixture();
  try {
    f.store.ingest({ update_id: 1, message: { message_id: 99, date: 1_700_000_000, chat: { id: -200 }, text: 'private source' } }, ['-200']);
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('remember', { subject: 'user', text: 'private source', message_id: 99, quote: 'private source' }), { stopReason: 'toolUse' }),
      context => {
        const results = context.messages.filter(m => m.role === 'toolResult');
        assert.ok(results.some(m => m.isError));
        return fauxAssistantMessage('Нет подтверждения в этом чате.');
      },
    ]);
    assert.equal(await f.create().respond('-100', 'remember:bad', 'Попробуй сохранить'), 'Нет подтверждения в этом чате.');
    assert.equal(f.memory.recall({ scope: '-100', query: '' }).length, 0);
  } finally { await f.close(); }
});

test('forget_memory hands off to clean Pi context and blocks forgotten sources after restart', { timeout: 10_000 }, async () => {
  const f = await fixture();
  try {
    const secret = 'моё кодовое слово ЛАЗУРНЫЙ_КЕДР_4821';
    f.store.ingest({ update_id: 1, message: { message_id: 42, date: 1_700_000_000, chat: { id: -100 }, from: { id: 7 }, text: secret } }, ['-100']);
    f.store.ingest({ update_id: 2, message: { message_id: 90, date: 1_700_000_000, chat: { id: -200 }, from: { id: 8 }, text: 'another chat is unaffected' } }, ['-200']);
    f.memory.remember({ scope: '-200', subject: 'user:8', text: 'another chat is unaffected', sources: [{ id: '-200:90', quote: 'another chat is unaffected' }] });
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('remember', { subject: 'user:7', text: secret, message_id: 42, quote: secret }), { stopReason: 'toolUse' }),
      context => {
        assert.ok(JSON.stringify(context.messages).includes(secret));
        return fauxAssistantMessage('Запомнил.');
      },
    ]);
    const first = f.create();
    assert.equal(await first.respond('-100', 'remember:secret', `Запомни: ${secret}`), 'Запомнил.');
    const memory = f.memory.recall({ scope: '-100', query: '' })[0]!;
    f.store.queue('quoted-secret', '-100', 'sendMessage', { text: `Ты сказал: ${secret}` });
    f.store.outgoingStatus('quoted-secret', 'sent', { message_id: 43, date: 1_700_000_001, chat: { id: -100 }, from: { id: 777, is_bot: true }, text: `Ты сказал: ${secret}` });
    f.store.ingest({ update_id: 3, message: { message_id: 44, date: 1_700_000_002, chat: { id: -100 }, from: { id: 7 }, text: 'Забудь сохранённую запись обо мне.' } }, ['-100']);
    f.store.set('route:-100', JSON.stringify({ messageId: 44, topic: 17 }));
    assert.deepEqual(f.store.conversation('-100', 3).filter(message => message.text?.includes(secret)).map(message => message.message_id), [42, 43], 'before forgetting, both the user source and our echoed reply exist');
    f.provider.appendResponses([
      fauxAssistantMessage(fauxToolCall('forget_memory', { id: memory.id }), { stopReason: 'toolUse' }),
      context => {
        assert.equal(JSON.stringify(context).includes('ЛАЗУРНЫЙ_КЕДР_4821'), false, 'handoff must exclude the original user input and remember result');
        return fauxAssistantMessage('Забыл.');
      },
    ]);
    assert.equal(await first.respond('-100', 'forget:secret', 'Забудь сохранённую запись обо мне.'), 'Забыл.');
    assert.equal(f.memory.recall({ scope: '-100', query: '' }).length, 0);
    assert.equal(f.memory.isSourceForgotten('-100', '-100:42'), true);
    assert.deepEqual(f.memory.remember({ scope: '-100', subject: 'user:7', text: 'Переформулированный факт', sources: [{ id: '-100:42', quote: secret }] }), { status: 'blocked', reason: 'forgotten' });
    assert.deepEqual(f.store.conversation('-100', 3), [], 'context cutoff must also remove the bot reply that echoed the forgotten source');
    assert.equal(f.memory.isSourceForgotten('-200', '-200:90'), false);
    assert.equal(f.memory.recall({ scope: '-200', query: '' }).length, 1);
    assert.deepEqual(f.store.conversation('-200', 3).map(message => message.text), ['another chat is unaffected']);
    await first.close();
    f.provider.appendResponses([context => {
      assert.equal(JSON.stringify(context).includes('ЛАЗУРНЫЙ_КЕДР_4821'), false, 'restarting Pi must retain the context handoff');
      return fauxAssistantMessage('Сохранённых фактов нет.');
    }]);
    f.store.ingest({ update_id: 4, message: { message_id: 45, date: 1_700_000_003, chat: { id: -100 }, from: { id: 7 }, text: 'Что ты помнишь обо мне?' } }, ['-100']);
    const recent = f.store.conversation('-100', 4).filter(message => !f.memory.isSourceForgotten('-100', `-100:${message.message_id}`));
    assert.deepEqual(recent.map(message => message.message_id), [45], 'new messages must remain visible without reviving the original source or echoed reply');
    assert.equal(await f.create().respond('-100', 'after:forget', JSON.stringify({ recent, request: 'Что ты помнишь обо мне?' })), 'Сохранённых фактов нет.');
  } finally { await f.close(); }
});
