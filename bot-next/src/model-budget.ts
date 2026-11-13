import { randomUUID } from 'node:crypto';
import type { Models } from '@earendil-works/pi-ai/models';
import type { Api, Model, Context, AssistantMessage, AssistantMessageEventStream as Stream } from '@earendil-works/pi-ai';
import { AssistantMessageEventStream } from '@earendil-works/pi-ai/utils/event-stream';
import type { Budget } from './budget.js';

export const PROBE_INPUT_CEILING = 32768;
/** Text uses a deliberately loose UTF-8 byte bound plus protocol overhead; images reserve the full window. */
export function inputCeiling(context: Context): number {
  if (context.messages.some(m => Array.isArray(m.content) && m.content.some(p => p.type === 'image'))) return Infinity;
  return Buffer.byteLength(JSON.stringify(context), 'utf8') + 4096 + 256 * (context.messages.length + (context.tools?.length ?? 0));
}
export function modelQuote(model: Model<Api>, maxOutput = 4096, inputTokens = model.contextWindow): number {
  const prices = [model.cost, ...(model.cost.tiers ?? [])];
  const rates = prices.flatMap(p => [p.input * 2, p.cacheRead, p.cacheWrite]);
  const outputs = prices.map(p => p.output);
  if ([...rates, ...outputs].some(n => !Number.isFinite(n) || n < 0)) throw new Error('Model has no valid price');
  const quote = Math.ceil(Math.min(model.contextWindow, inputTokens) * Math.max(...rates) + Math.min(maxOutput, model.maxTokens) * Math.max(...outputs));
  if (!Number.isSafeInteger(quote) || quote <= 0) throw new Error('A priced model is required');
  return quote;
}

export function failureMessage(model: Model<Api>, message: string): AssistantMessage {
  return { role: 'assistant', content: [], api: model.api, provider: model.provider, model: model.id, stopReason: 'error', errorMessage: message,
    timestamp: Date.now(), usage: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, totalTokens: 0, cost: { input: 0, output: 0, cacheRead: 0, cacheWrite: 0, total: 0 } } };
}

/** Intercepts all model requests, including background compaction. Unknown bills remain reserved. */
export function budgetedModels(models: Models, budget: Budget, chatId: string): Models {
  const wrap = (model: Model<Api>, modelContext: Context, start: () => Stream): Stream => {
    const output = new AssistantMessageEventStream();
    const id = `model:${randomUUID()}`;
    if (!model.input.includes('image') && modelContext.messages.some(message => Array.isArray(message.content) && message.content.some(item => item.type === 'image'))) {
      output.push({ type: 'error', reason: 'error', error: failureMessage(model, 'Configured model does not support image input') });
      return output;
    }
    try { budget.reserve(id, chatId, modelQuote(model, 4096, inputCeiling(modelContext))); }
    catch {
      output.push({ type: 'error', reason: 'error', error: failureMessage(model, 'Model budget unavailable') });
      return output;
    }
    void (async () => {
      try {
        const input = start();
        for await (const event of input) {
          if (event.type === 'done' || event.type === 'error') {
            const message = event.type === 'done' ? event.message : event.error;
            // Zero usage after a transport error is not evidence that nothing was billed.
            if (message.stopReason !== 'error' && message.stopReason !== 'aborted') {
              const micros = Math.ceil(message.usage.cost.total * 1e6);
              budget.settle(id, micros);
            }
          }
          output.push(event);
        }
        output.end(await input.result());
      } catch {
        output.push({ type: 'error', reason: 'error', error: failureMessage(model, 'Model request interrupted; cost reservation retained') });
      }
    })();
    return output;
  };
  return new Proxy(models, {
    get(target, property) {
      if (property === 'streamSimple') return ((model, context, options) => wrap(model, context, () => target.streamSimple(model, context, { ...options, maxTokens: 4096, maxRetries: 0 }))) satisfies Models['streamSimple'];
      if (property === 'completeSimple') return ((model, context, options) => wrap(model, context, () => target.streamSimple(model, context, { ...options, maxTokens: 4096, maxRetries: 0 })).result()) satisfies Models['completeSimple'];
      if (property === 'stream') return ((model, context, options) => wrap(model, context, () => target.stream(model, context, { ...options, maxTokens: 4096, maxRetries: 0 } as NonNullable<typeof options>))) satisfies Models['stream'];
      if (property === 'complete') return ((model, context, options) => wrap(model, context, () => target.stream(model, context, { ...options, maxTokens: 4096, maxRetries: 0 } as NonNullable<typeof options>)).result()) satisfies Models['complete'];
      if (['classify', 'generateImages', 'streamDeferred', 'fetchDeferred'].includes(String(property))) return () => { throw new Error('Use budgeted host tools'); };
      const value: unknown = Reflect.get(target, property);
      return typeof value === 'function' ? value.bind(target) : value;
    },
  });
}
