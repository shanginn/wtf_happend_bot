import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { createHash } from 'node:crypto';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { BACKGROUND_CONTEXT } from '@earendil-works/chord/context';
import { Harness, createRegistry, defineExtension, defineTool } from '@earendil-works/pi-durable';
import { openNodeSqliteStorage } from '@earendil-works/pi-durable/storage/sqlite/node';
import { createModels } from '@earendil-works/pi-ai/models';
import { fauxProvider, fauxAssistantMessage, fauxToolCall } from '@earendil-works/pi-ai/providers/faux';
import { map, obj, str, unknown } from '@earendil-works/pi-schema';
import { Store } from '../src/store.js';
import { Budget } from '../src/budget.js';
import { MemoryStore } from '../src/memory.js';
import { Stars } from '../src/stars.js';
import { Telegram } from '../src/telegram.js';
import { callTelegramTool } from '../src/telegram-tool.js';
import { Runtime } from '../src/runtime.js';

test('an admitted v0 Pi turn upgrades to a canonical final edit, with no extra send or model call', { timeout: 20_000 }, async () => {
  for (const uncertain of [false, true]) {
    const directory = await mkdtemp(join(tmpdir(), 'wtf-reply-upgrade-')), db = new DatabaseSync(':memory:');
    const store = new Store(db), budget = new Budget(db, 10_000_000), memory = new MemoryStore(db), stars = new Stars(db, { signingKey: 'x'.repeat(32), botId: 'fixture' });
    const provider = fauxProvider({ provider: 'offline', api: 'offline', models: [{ id: 'test', contextWindow: 128000, maxTokens: 4096, cost: { input: .1, output: .2, cacheRead: .01, cacheWrite: .1 } }] });
    const models = createModels(); models.setProvider(provider.provider);
    let sends = 0, edits = 0;
    const telegram = new Telegram('fixture-token', async (url, init) => {
      if (String(url).endsWith('sendMessage')) { sends++; if (uncertain) throw new Error('Unconfirmed old send'); }
      if (String(url).endsWith('editMessageText')) edits++;
      return Response.json({ ok: true, result: { message_id: 77, chat: { id: -100 }, text: JSON.parse(init!.body as string).text } });
    });
    let runtime: Runtime | undefined;
    try {
      const registry = createRegistry();
      registry.install(defineExtension({ name: 'chat', tools: [defineTool({ name: 'telegram_api', description: 'Protocol-v0 literal Telegram transport fixture.', parameters: obj({ method: str(), parameters: map(unknown()) }), replay: 'safe',
        execute: async ({ method, parameters }, api) => ({ output: [{ type: 'text' as const, text: JSON.stringify(await callTelegramTool(store, telegram, `telegram-api:-100:${api.taskId}`, '-100', method, parameters)) }] }) })] }));
      const path = join(directory, `${createHash('sha256').update('-100').digest('hex')}.sqlite`);
      const old = await Harness.open(await openNodeSqliteStorage(path), { models, registry, settings: { toolExecution: 'sequential', stream: { maxRetries: 0 }, retry: { maxRetries: 0 } } }, BACKGROUND_CONTEXT);
      const root = await old.root(BACKGROUND_CONTEXT, { agent: { model: { provider: 'offline', modelId: 'test' }, maxTurns: 8 } }); old.resume();
      const final = 'Полный окончательный ответ с важным новым уточнением.';
      provider.setResponses([
        fauxAssistantMessage(fauxToolCall('telegram_api', { method: 'sendMessage', parameters: { chat_id: '-100', text: 'Ранний ответ старой версии.', reply_parameters: { message_id: 42 } } }), { stopReason: 'toolUse' }),
        fauxAssistantMessage(final),
      ]);
      const admitted = await root.submit({ type: 'input', requestId: 'telegram:1', content: 'Request', entry: { kind: 'telegram.message', data: { telegramUpdateId: 1 } } }, BACKGROUND_CONTEXT);
      assert.equal((await admitted.wait(BACKGROUND_CONTEXT)).status, 'done'); await old.close(BACKGROUND_CONTEXT);
      runtime = new Runtime({ directory, provider: 'offline', model: 'test', models, store, budget, memory, stars, telegram });
      const delivery = runtime.replies.open('-100', 'telegram:1', { chat: '-100', messageId: 42 });
      assert.equal(await runtime.respond('-100', 'telegram:1', 'Request', undefined, { kind: 'telegram.message', data: { telegramUpdateId: 1, replyProtocol: { version: 1, id: delivery.id } } }), final);
      runtime.replies.complete(delivery, final); assert.equal(provider.state.callCount, 2); assert.equal(sends, 1);
      assert.equal(runtime.replies.row(delivery.id)!.final_text, final);
      if (uncertain) {
        assert.equal(store.outgoing().length, 0); assert.equal(runtime.replies.row(delivery.id)!.blocked, 'LEGACY_DELIVERY_UNKNOWN');
      } else {
        const out = store.outgoing()[0]!; assert.equal(out.method, 'editMessageText'); assert.equal(JSON.parse(out.payload).text, final);
        assert.equal(store.claimOutgoing(out.id), true); store.outgoingStatus(out.id, 'sent', await telegram.call(out.method, JSON.parse(out.payload)));
        runtime.replies.complete(delivery, final); assert.equal(store.outgoing().length, 0); assert.equal(edits, 1); assert.equal(sends, 1);
      }
    } finally { await runtime?.close(); db.close(); await rm(directory, { recursive: true, force: true }); }
  }
});
