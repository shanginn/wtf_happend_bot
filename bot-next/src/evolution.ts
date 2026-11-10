import { createHash } from 'node:crypto';
import { resolve } from 'node:path';
import { Budget, atomic, integer } from './budget.js';
import { HARNESS2_COMMIT, harnessHash, validateHarness, type HarnessTree } from './skills.js';
import { runHarness2 } from './harness2/core.js';

export const HARNESS2_IMPLEMENTATION = 'wtf-harness2-typescript-v1';

export type EvolutionRequest = {
  taskId: string; chatId: string | number; prompt: string; base: HarnessTree;
  libraryRoot?: string; k?: 1 | 2; mode?: 'parallel' | 'sequential';
  maximumCalls: number; maximumCostMicros: number; perCallMaximumMicros: number;
  timeoutMs?: number;
};
type Context = { taskId: string; label: string; effects: 'simulated'; maxCostMicros: number; signal: AbortSignal };
export type ProposeRequest = Context & { mission: string; evidence: Record<string, string> };
export type ProbeRequest = Context & { tree: HarnessTree; prompt: string };
export type EvolutionCallbacks = {
  propose(request: ProposeRequest): Promise<{ text: string; files?: HarnessTree; costMicros: number }>;
  probe(request: ProbeRequest): Promise<{ answer: string; trajectory: string; costMicros: number }>;
  validate?(tree: HarnessTree): Promise<void>;
};
export type EvolutionResult = {
  engineImplementation?: string;
  engineCommit: string; tree: HarnessTree; selectedLabel: string;
  experience: Record<string, unknown>; summary: Record<string, unknown>;
  costMicros: number; calls: number;
};

function digest(value: unknown): string { return createHash('sha256').update(JSON.stringify(value)).digest('hex'); }

/** Cancellation remains bounded even when a provider ignores its AbortSignal. */
async function abortable<T>(work: Promise<T>, signal: AbortSignal): Promise<T> {
  signal.throwIfAborted();
  let abort = () => {};
  const cancelled = new Promise<never>((_, reject) => {
    abort = () => reject(signal.reason ?? new Error('Evolution cancelled'));
    signal.addEventListener('abort', abort, { once: true });
    if (signal.aborted) abort();
  });
  try { return await Promise.race([work, cancelled]); }
  finally { signal.removeEventListener('abort', abort); }
}

/** Budgeted host for the native TypeScript port of the pinned Harness² algorithm. */
export class Harness2Runner {
  readonly budget: Budget;
  constructor(budget: Budget) {
    this.budget = budget;
    budget.db.exec('CREATE TABLE IF NOT EXISTS evolution_runs (operation_id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, result_json TEXT NOT NULL)');
  }

  async run(request: EvolutionRequest, callbacks: EvolutionCallbacks): Promise<EvolutionResult> {
    validateHarness(request.base);
    integer(request.maximumCalls, 'evolution call limit', 1);
    integer(request.maximumCostMicros, 'evolution budget', 1);
    integer(request.perCallMaximumMicros, 'call budget', 1);
    if (request.timeoutMs !== undefined) integer(request.timeoutMs, 'evolution deadline', 1);
    if (!request.taskId || request.taskId.length > 200 || !request.prompt || request.prompt.length > 64_000) throw new Error('Invalid evolution task');
    const k = request.k ?? 1;
    if (![1, 2].includes(k)) throw new Error('Invalid recursion budget');
    const mode = request.mode ?? 'parallel';
    if (mode !== 'parallel' && mode !== 'sequential') throw new Error('Invalid evolution mode');
    if (request.perCallMaximumMicros > request.maximumCostMicros) throw new Error('Call budget exceeds total budget');
    const operationId = `harness2:${digest([String(request.chatId), request.taskId])}`;
    const fingerprint = digest({ ...request, base: harnessHash(request.base), implementation: HARNESS2_IMPLEMENTATION, engineCommit: HARNESS2_COMMIT });
    const cached = this.budget.db.prepare('SELECT fingerprint, result_json FROM evolution_runs WHERE operation_id=?').get(operationId) as { fingerprint: string; result_json: string } | undefined;
    if (cached) {
      if (cached.fingerprint !== fingerprint) throw new Error('Evolution task identity conflict');
      return JSON.parse(cached.result_json) as EvolutionResult;
    }
    if (this.budget.get(operationId)) throw new Error('Evolution run already attempted; use a new task ID');
    this.budget.reserve(operationId, request.chatId, request.maximumCostMicros);
    let calls = 0, costMicros = 0, closed = false;
    const unconfirmed = new Set<number>();
    const controller = new AbortController();
    const timeoutMs = Math.min(request.timeoutMs ?? 180_000, 300_000);
    const timer = setTimeout(() => controller.abort(new Error('Evolution deadline exceeded')), timeoutMs);
    const paid = async <T extends { costMicros: number }>(label: string, invoke: (common: Context) => Promise<T>): Promise<T> => {
      controller.signal.throwIfAborted();
      if (closed) throw new Error('Evolution run is closed');
      if (calls >= request.maximumCalls || costMicros + (unconfirmed.size + 1) * request.perCallMaximumMicros > request.maximumCostMicros) {
        const error = new Error('Evolution budget exhausted'); controller.abort(error); throw error;
      }
      const call = ++calls;
      unconfirmed.add(call);
      try {
        const answer = await abortable(Promise.resolve().then(() => invoke({ taskId: request.taskId, label, effects: 'simulated',
          maxCostMicros: request.perCallMaximumMicros, signal: controller.signal })), controller.signal);
        controller.signal.throwIfAborted();
        integer(answer.costMicros, 'evolution provider cost');
        if (answer.costMicros > request.perCallMaximumMicros) throw new Error('Provider exceeded the declared cost ceiling');
        costMicros += answer.costMicros;
        unconfirmed.delete(call);
        if (Buffer.byteLength(JSON.stringify(answer)) > 1_000_000) throw new Error('Evolution answer too large');
        return answer;
      } catch (error) {
        // A paid transport failure cannot become an unaccounted retry in the engine.
        controller.abort(error); throw error;
      }
    };
    try {
      const engineResult = await abortable(runHarness2({ taskId: digest([String(request.chatId), request.taskId]), domain: digest(String(request.chatId)),
        prompt: request.prompt, base: structuredClone(request.base), k, mode, signal: controller.signal,
        libraryRoot: request.libraryRoot ? resolve(request.libraryRoot) : undefined,
      }, {
        propose: (label, mission, evidence) => paid(label, common => callbacks.propose({ ...common, mission, evidence: structuredClone(evidence) })),
        probe: (label, tree, prompt) => paid(label, common => callbacks.probe({ ...common, tree: structuredClone(tree), prompt })),
        validate: async tree => {
          controller.signal.throwIfAborted(); validateHarness(tree);
          if (callbacks.validate) await abortable(callbacks.validate(structuredClone(tree)), controller.signal);
          controller.signal.throwIfAborted();
        },
      }), controller.signal);
      controller.signal.throwIfAborted();
      validateHarness(engineResult.tree);
      const result: EvolutionResult = { ...engineResult, engineCommit: HARNESS2_COMMIT, engineImplementation: HARNESS2_IMPLEMENTATION, costMicros, calls };
      atomic(this.budget.db, () => {
        this.budget.settle(operationId, costMicros);
        this.budget.db.prepare('INSERT INTO evolution_runs VALUES (?, ?, ?)').run(operationId, fingerprint, JSON.stringify(result));
      });
      return result;
    } catch (error) {
      controller.abort(error);
      this.budget.settle(operationId, unconfirmed.size ? request.maximumCostMicros : costMicros);
      throw error;
    } finally {
      closed = true; clearTimeout(timer); controller.abort();
    }
  }
}
