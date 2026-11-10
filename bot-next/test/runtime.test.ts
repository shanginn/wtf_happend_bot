import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
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
import { InternetSearch } from '../src/search.js';

async function fixture() {
  const directory = await mkdtemp(join(tmpdir(), 'wtf-pi-runtime-test-'));
  const db = new DatabaseSync(':memory:');
  const provider = fauxProvider({ provider: 'offline', api: 'offline', models: [{ id: 'test', contextWindow: 128_000, maxTokens: 4096, cost: { input: 0.1, output: 0.2, cacheRead: 0.01, cacheWrite: 0.1 } }] });
  const models = createModels(); models.setProvider(provider.provider);
  const memory = new MemoryStore(db), store = new Store(db), budget = new Budget(db, 10_000_000);
  const stars = new Stars(db, { signingKey: 'x'.repeat(32), botId: 'offline' });
  const options = { directory, provider: 'offline', model: 'test', models, memory, store, budget, stars };
  const runtimes: Runtime[] = [];
  return { provider, memory, store, budget, create(telegram?: Telegram, search?: InternetSearch) { const runtime = new Runtime({ ...options, telegram, search }); runtimes.push(runtime); return runtime; },
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
