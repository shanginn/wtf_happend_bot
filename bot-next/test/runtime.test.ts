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
import { type ToolFeedback, toolFeedback } from '../src/tool-feedback.js';
import { appendTelegramImages } from '../src/telegram-images.js';
import { tinyPng } from './image-fixture.js';

function editorInput(context: { messages: readonly import('@earendil-works/pi-ai').Message[] }): { request: string; text: string; current_tool_results: ToolFeedback[] } {
  const input = context.messages.find(message => message.role === 'user');
  assert.ok(input);
  const text = typeof input.content === 'string' ? input.content : input.content.flatMap(item => item.type === 'text' ? [item.text] : []).join('\n');
  return JSON.parse(text);
}

async function fixture() {
  const directory = await mkdtemp(join(tmpdir(), 'wtf-pi-runtime-test-'));
  const db = new DatabaseSync(':memory:');
  const provider = fauxProvider({ provider: 'offline', api: 'offline', models: [{ id: 'test', input: ['text', 'image'], contextWindow: 128_000, maxTokens: 4096, cost: { input: 0.1, output: 0.2, cacheRead: 0.01, cacheWrite: 0.1 } }] });
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

test('image documents enter Pi as native pixels with attribution and survive final language correction and replay', { timeout: 10_000 }, async () => {
  const f = await fixture(); let downloads = 0;
  const telegram = new Telegram('private-token', async url => {
    downloads++;
    return String(url).endsWith('/getFile') ? Response.json({ ok: true, result: { file_path: 'documents/fixture.png' } }) : new Response(tinyPng);
  });
  try {
    const message = { message_id: 42, date: 1794469813, chat: { id: -100, type: 'supergroup' }, from: { id: 7, first_name: 'Ник' }, caption: 'Что на картинке?', document: { file_id: 'fixture-image', mime_type: 'image/png' } };
    const content = buildTelegramInput({ current: { message, updateId: 1, kind: 'message' }, history: [], timezone: 'Asia/Yekaterinburg', bot: { id: 777, first_name: 'Bot', is_bot: true }, now: 1794469813000, memories: [] });
    await appendTelegramImages(content, telegram, message, [], () => true, 'Asia/Yekaterinburg');
    f.provider.setResponses([
      context => {
        const images = context.messages.flatMap(message => Array.isArray(message.content) ? message.content.filter(item => item.type === 'image') : []);
        assert.equal(images.length, 1); assert.equal(images[0]!.data, tinyPng.toString('base64'));
        assert.ok(JSON.stringify(context.messages).includes('telegram_user:7'));
        return fauxAssistantMessage('这是一张非常小的图片，无法从中读出文字或辨认具体物体。');
      },
      context => {
        const input = context.messages.find(message => message.role === 'user')!;
        assert.ok(Array.isArray(input.content));
        assert.equal(input.content.filter(item => item.type === 'image').length, 1);
        const first = input.content[0]!; assert.equal(first.type, 'text');
        if (first.type === 'text') { assert.match(JSON.parse(first.text).current_images[0].attribution, /telegram_user:7/); assert.ok(!first.text.includes(tinyPng.toString('base64'))); }
        return fauxAssistantMessage('Картинка слишком маленькая, чтобы различить детали.');
      },
    ]);
    const runtime = f.create(telegram), answer = await runtime.respond('-100', 'image:direct', content, message.caption);
    assert.equal(answer, 'Картинка слишком маленькая, чтобы различить детали.');
    assert.equal(downloads, 2); await runtime.close();
    assert.equal(await f.create(telegram).respond('-100', 'image:direct', content, message.caption), answer);
    assert.equal(downloads, 2); assert.equal(f.provider.state.callCount, 2);
  } finally { await f.close(); }
});

test('view_telegram_image returns history pixels to the model and final editor without repeating downloads on replay', { timeout: 10_000 }, async () => {
  const f = await fixture(); let downloads = 0;
  const telegram = new Telegram('private-token', async url => {
    downloads++;
    return String(url).endsWith('/getFile') ? Response.json({ ok: true, result: { file_path: 'documents/fixture.png', file_size: tinyPng.length } }) : new Response(tinyPng);
  });
  try {
    f.store.ingest({ update_id: 1, message: { message_id: 1, date: 1794469813, chat: { id: -100 }, from: { id: 7, first_name: 'Ник' }, document: { file_id: 'history-image', mime_type: 'image/png' } } });
    f.store.ingest({ update_id: 2, message: { message_id: 2, date: 1794469820, chat: { id: -100 }, text: 'Посмотри картинку выше.' } });
    const runtime = f.create(telegram), delivery = runtime.replies.open('-100', 'telegram:2', { chat: '-100', messageId: 2 }, 2);
    const entry = { kind: 'telegram.message', data: { telegramUpdateId: 2, replyProtocol: { ...delivery } } };
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('view_telegram_image', { message_id: 1 }), { stopReason: 'toolUse' }),
      context => {
        const result = context.messages.find(message => message.role === 'toolResult' && message.toolName === 'view_telegram_image');
        assert.equal(result?.role, 'toolResult');
        if (result?.role === 'toolResult') { assert.equal(result.isError, false); assert.ok(result.content.some(item => item.type === 'image' && item.data === tinyPng.toString('base64'))); }
        assert.ok(JSON.stringify(result).includes('telegram_user:7'));
        return fauxAssistantMessage('这张图片太小，无法辨认其中的具体物体，也看不到可读取的文字。');
      },
      context => {
        const input = context.messages.find(message => message.role === 'user')!; assert.ok(Array.isArray(input.content));
        assert.equal(input.content.filter(item => item.type === 'image').length, 1);
        const first = input.content[0]!; assert.equal(first.type, 'text');
        if (first.type === 'text') {
          const data = JSON.parse(first.text); assert.equal(data.current_tool_results[0].call.name, 'view_telegram_image');
          assert.equal(data.current_tool_results[0].results[0].content[1].image_ref, 'image:1');
          assert.ok(!first.text.includes(tinyPng.toString('base64')));
        }
        return fauxAssistantMessage('Посмотрел: деталей на такой маленькой картинке не разобрать.');
      },
    ]);
    const answer = await runtime.respond('-100', 'telegram:2', 'Посмотри картинку выше.', 'Посмотри картинку выше.', entry);
    assert.match(answer, /Посмотрел/); assert.equal(downloads, 2); await runtime.close();
    assert.equal(await f.create(telegram).respond('-100', 'telegram:2', 'Посмотри картинку выше.', 'Посмотри картинку выше.', entry), answer);
    assert.equal(downloads, 2); assert.equal(f.provider.state.callCount, 3);
  } finally { await f.close(); }
});

test('image tool cannot read forgotten, future or cross-chat images even when their message IDs are supplied', { timeout: 10_000 }, async () => {
  const f = await fixture(); let downloads = 0;
  const telegram = new Telegram('private-token', async () => { downloads++; throw new Error('must not fetch a hidden image'); });
  try {
    for (const id of [1, 4, 5, 8, 9]) f.store.ingest({ update_id: id, message: { message_id: id, date: 1794469800 + id, chat: { id: id === 5 ? -200 : -100 }, ...(id === 8 ? { text: 'Посмотри картинки.' } : { document: { file_id: `image-${id}`, mime_type: 'image/png' } }) } });
    f.store.set('memory:context-floor:-100', '1');
    const fact = f.memory.remember({ scope: '-100', subject: 'fixture', text: 'Forgotten', sources: [{ id: '-100:4', quote: 'Forgotten' }] });
    assert.ok('memory' in fact);
    f.memory.forget({ scope: '-100', id: fact.memory.id });
    const runtime = f.create(telegram), delivery = runtime.replies.open('-100', 'telegram:8', { chat: '-100', messageId: 8 }, 8);
    f.provider.setResponses([
      fauxAssistantMessage([1, 4, 5, 9].map(message_id => fauxToolCall('view_telegram_image', { message_id })), { stopReason: 'toolUse' }),
      context => {
        const results = context.messages.filter(message => message.role === 'toolResult'); assert.equal(results.length, 4);
        assert.ok(results.every(result => result.isError && result.content.every(item => item.type !== 'image')));
        return fauxAssistantMessage('Эти картинки недоступны в текущем контексте.');
      },
    ]);
    assert.equal(await runtime.respond('-100', 'telegram:8', 'Посмотри картинки.', 'Посмотри картинки.', { kind: 'telegram.message', data: { telegramUpdateId: 8, replyProtocol: { ...delivery } } }), 'Эти картинки недоступны в текущем контексте.');
    assert.equal(downloads, 0);
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

test('language final receives confirmed tool results with zero tools, never repeats effects and survives replay', { timeout: 10_000 }, async () => {
  const f = await fixture();
  try {
    let sends = 0;
    const telegram = new Telegram('private-token', async () => { sends++; return Response.json({ ok: true, result: { message_id: 42 } }); });
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendPoll', parameters: { chat_id: '-100', question: 'Восстановить память?', options: [{ text: 'Да' }, { text: 'Нет' }] } }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('投票已经创建了。现在大家可以选择是否恢复记忆，我不会再次重复发送这个投票。'),
      context => {
        assert.equal(context.messages.flatMap(message => message.role === 'system' ? message.toolsAdded ?? [] : []).length, 0);
        const input = editorInput(context);
        assert.equal(input.request, 'Создай опрос о восстановлении памяти.');
        assert.equal(input.current_tool_results.length, 1);
        const feedback = input.current_tool_results[0]!;
        assert.equal(feedback.call.arguments.method, 'sendPoll');
        assert.equal(feedback.results[0]!.toolCallId, feedback.call.id);
        assert.equal(feedback.results[0]!.isError, false);
        const result = feedback.results[0]!.content[0]!;
        assert.equal(result.type, 'text');
        if (result.type === 'text') assert.deepEqual(JSON.parse(result.text), { ok: true, result: { message_id: 42 } });
        assert.ok(!JSON.stringify(input).includes('private-token'));
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

test('every tool round feeds the next model call and the language final retains this input errors and draft receipt', { timeout: 10_000 }, async () => {
  const f = await fixture(); let requests = 0;
  const telegram = new Telegram('private-token', async url => {
    requests++;
    if (String(url).endsWith('/getChat')) return Response.json({ ok: true, result: { id: -100, title: 'OLD_RESULT_IGNORED' } });
    assert.ok(String(url).endsWith('/sendPoll'));
    return Response.json({ ok: false, error_code: 403, description: 'Not enough rights to send polls' }, { status: 403 });
  });
  const final = 'Факт не сохранён: нет подтверждающей цитаты. Опрос создать не удалось — не хватает прав.';
  try {
    f.provider.setResponses([
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'getChat', parameters: { chat_id: '-100' } }, { id: 'old-call' }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('Получил прежний результат.'),
    ]);
    const old = f.create(telegram); await old.respond('-100', 'old:feedback', 'Покажи чат.'); await old.close();
    const runtime = f.create(telegram), delivery = runtime.replies.open('-100', 'telegram:2', { chat: '-100', messageId: 2 });
    const entry = { kind: 'telegram.message', data: { telegramUpdateId: 2, replyProtocol: { ...delivery } } };
    f.provider.appendResponses([
      fauxAssistantMessage(fauxToolCall('remember', { subject: 'user', text: 'Неподтверждённый факт', message_id: 99, quote: 'Нет такого источника' }, { id: 'memory-failed' }), { stopReason: 'toolUse' }),
      context => {
        const feedback = toolFeedback(context.messages).find(item => item.call.id === 'memory-failed');
        assert.equal(feedback?.results[0]?.isError, true, 'the next round must see the actual tool error');
        return fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendPoll', parameters: { chat_id: '-100', question: 'Продолжить?', options: [{ text: 'Да' }, { text: 'Нет' }] } }, { id: 'poll-rejected' }), { stopReason: 'toolUse' });
      },
      context => {
        const feedback = toolFeedback(context.messages).filter(item => ['memory-failed', 'poll-rejected'].includes(item.call.id));
        assert.equal(feedback.length, 2);
        assert.ok(JSON.stringify(feedback).includes('Not enough rights'));
        return fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendMessage', parameters: { chat_id: '-100', text: 'Предварительный ответ.' } }, { id: 'reply-draft' }), { stopReason: 'toolUse' });
      },
      context => {
        const receipt = toolFeedback(context.messages).find(item => item.call.id === 'reply-draft');
        const result = receipt?.results[0]?.content[0];
        assert.equal(result?.type, 'text');
        if (result?.type === 'text') assert.equal(JSON.parse(result.text).sent, false, 'the final model call sees the unsent draft receipt');
        return fauxAssistantMessage('事实已保存，投票也已经创建，所有操作都已成功完成。');
      },
      context => {
        assert.equal(context.messages.flatMap(message => message.role === 'system' ? message.toolsAdded ?? [] : []).length, 0);
        const input = editorInput(context), feedback = input.current_tool_results;
        assert.deepEqual(feedback.map(item => item.call.id), ['memory-failed', 'poll-rejected', 'reply-draft']);
        assert.equal(feedback[0]!.results[0]!.isError, true);
        const rejected = feedback[1]!.results[0]!.content[0]!, draft = feedback[2]!.results[0]!.content[0]!;
        assert.equal(rejected.type, 'text'); assert.equal(draft.type, 'text');
        if (rejected.type === 'text' && draft.type === 'text') {
          assert.equal(JSON.parse(rejected.text).outcome, 'rejected'); assert.equal(JSON.parse(draft.text).sent, false);
        }
        assert.ok(!JSON.stringify(input).includes('OLD_RESULT_IGNORED'));
        return fauxAssistantMessage(final);
      },
    ]);
    assert.equal(await runtime.respond('-100', 'telegram:2', 'Запомни факт и создай опрос.', 'Запомни факт и создай опрос.', entry), final);
    runtime.replies.complete(delivery, final);
    assert.equal(requests, 2, 'only the old read and the rejected poll reached the fake transport');
    assert.equal(f.store.outgoing().length, 1); assert.equal(JSON.parse(f.store.outgoing()[0]!.payload).text, final);
    const calls = f.provider.state.callCount; await runtime.close();
    assert.equal(await f.create(telegram).respond('-100', 'telegram:2', 'Запомни факт и создай опрос.', 'Запомни факт и создай опрос.', entry), final);
    assert.equal(f.provider.state.callCount, calls); assert.equal(requests, 2);
  } finally { await f.close(); }
});

test('search final receives non-search action receipts alongside search results and never repeats either tool', { timeout: 10_000 }, async () => {
  const f = await fixture(); let polls = 0, searches = 0;
  const telegram = new Telegram('private-token', async () => { polls++; return Response.json({ ok: true, result: { message_id: 42, poll: { id: 'confirmed-poll' } } }); });
  const search = new InternetSearch('http://searxng:8080', async () => { searches++; return Response.json({ results: [{ title: 'Источник', url: 'https://example.org/news' }] }); });
  try {
    f.provider.setResponses([
      fauxAssistantMessage([
        fauxToolCall('telegram_api', { method: 'sendPoll', parameters: { chat_id: '-100', question: 'Обсудим?', options: [{ text: 'Да' }, { text: 'Нет' }] } }, { id: 'poll' }),
        fauxToolCall('internet_search', { query: 'новости' }, { id: 'search' }),
      ], { stopReason: 'toolUse' }),
      fauxAssistantMessage('Опрос создан, но поиск заблокирован CAPTCHA.'),
      context => {
        const feedback = editorInput(context).current_tool_results;
        assert.deepEqual(feedback.map(item => item.call.id), ['poll', 'search']);
        assert.ok(JSON.stringify(feedback[0]).includes('confirmed-poll'));
        assert.ok(JSON.stringify(feedback[1]).includes('https://example.org/news'));
        assert.equal(context.messages.flatMap(message => message.role === 'system' ? message.toolsAdded ?? [] : []).length, 0);
        return fauxAssistantMessage('Опрос создан. Нашёл источник: https://example.org/news');
      },
    ]);
    const runtime = f.create(telegram, search), answer = await runtime.respond('-100', 'search:with-action', 'Создай опрос и найди новости.');
    assert.equal(answer, 'Опрос создан. Нашёл источник: https://example.org/news');
    const calls = f.provider.state.callCount; await runtime.close();
    assert.equal(await f.create(telegram, search).respond('-100', 'search:with-action', 'Создай опрос и найди новости.'), answer);
    assert.equal(polls, 1); assert.equal(searches, 1); assert.equal(f.provider.state.callCount, calls);
  } finally { await f.close(); }
});

test('final tool context and persisted editors stay isolated for equal request IDs in separate sessions of one chat', { timeout: 10_000 }, async () => {
  const f = await fixture(); let requests = 0;
  const telegram = new Telegram('private-token', async () => Response.json({ ok: true, result: { id: -100, title: `session-${++requests}` } }));
  try {
    f.provider.setResponses([1, 2].flatMap(id => [
      fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'getChat', parameters: { chat_id: '-100' } }, { id: 'same-call-id' }), { stopReason: 'toolUse' }),
      fauxAssistantMessage('已经收到聊天信息，现在将根据结果用俄语回复用户。'),
      (context: Parameters<typeof editorInput>[0]) => {
        const input = editorInput(context), feedback = input.current_tool_results;
        assert.equal(input.request, `Запрос сессии ${id}.`);
        assert.equal(feedback.length, 1);
        const result = feedback[0]!.results[0]!.content[0]!;
        assert.equal(result.type, 'text');
        if (result.type === 'text') assert.equal(JSON.parse(result.text).result.title, `session-${id}`);
        return fauxAssistantMessage(`Результат ${id}.`);
      },
    ]));
    const runtime = f.create(telegram);
    for (const id of [1, 2]) assert.equal(await runtime.respond('-100', 'same-request', `Запрос сессии ${id}.`, `Запрос сессии ${id}.`, undefined, `widget:session:${id}`), `Результат ${id}.`);
    const calls = f.provider.state.callCount; await runtime.close();
    const restarted = f.create(telegram);
    for (const id of [1, 2]) assert.equal(await restarted.respond('-100', 'same-request', `Запрос сессии ${id}.`, `Запрос сессии ${id}.`, undefined, `widget:session:${id}`), `Результат ${id}.`);
    assert.equal(requests, 2); assert.equal(f.provider.state.callCount, calls);
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
