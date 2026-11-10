// Copyright 2026 Google LLC
// SPDX-License-Identifier: Apache-2.0
// Modified: TypeScript port of Harness² recursion.py / ledger.py at commit
// 6868817518876de8fd170c6a8354daca273b6191, retaining the prior Pi adapter's strict gates.
// Provider execution and budgets remain host callbacks. No authored code runs in this module.
import { isDeepStrictEqual } from 'node:util';
import type { HarnessTree } from '../skills.js';
import { assertNoGraderVerdicts, buildContext, mission } from './context.js';
import { ExperienceLibrary } from './library.js';
import { reflectAdoption, selectCandidates } from './selection.js';
import * as prompts from './prompts.js';
import type { Candidate, Evidence, Harness2Callbacks, Harness2Request, Harness2Result, Stage } from './types.js';

export const STAGE_ATTEMPTS = 2;
// The original bot bridge chose one worker; callers can opt into upstream's width of two.
export const PHASE_WIDTH = 1;
const clone = (tree: HarnessTree): HarnessTree => ({ ...tree });
const stageError = (error: unknown) => (error instanceof Error ? error.message : String(error)).slice(0, 400);

/** Same transport surface check as the former Pi bridge, before recursive validation. */
function validateFiles(tree: unknown): asserts tree is HarnessTree {
  if (!tree || typeof tree !== 'object' || Array.isArray(tree)) throw new Error('A harness must have between 1 and 100 files');
  const entries = Object.entries(tree);
  if (!entries.length || entries.length > 100) throw new Error('A harness must have between 1 and 100 files');
  let bytes = 0;
  for (const [path, body] of entries) {
    if (typeof body !== 'string') throw new Error('Harness paths and content must be strings');
    if (path !== 'AGENTS.md' && !/^\.agents\/skills\/[a-z0-9][a-z0-9_-]*\/(?:SKILL\.md|scripts\/[a-z0-9][a-z0-9_-]*\.(?:js|ts))$/.test(path)) throw new Error('Path outside the Pi editable surface');
    bytes += Buffer.byteLength(body);
  }
  if (bytes > 256_000 || typeof (tree as HarnessTree)['AGENTS.md'] !== 'string' || !(tree as HarnessTree)['AGENTS.md']!.trim()) throw new Error('Harness exceeds its content budget or lacks AGENTS.md');
}

/** Repair missing trigger lines exactly as the upstream Pi surface did. */
function autotrigger(tree: HarnessTree): void {
  for (const path of Object.keys(tree).sort()) {
    const name = /^\.agents\/skills\/([^/]+)\/SKILL\.md$/.exec(path)?.[1];
    if (!name || tree['AGENTS.md']!.includes(name)) continue;
    const line = `Use ${name} when relevant.`;
    if (tree['AGENTS.md']!.length + line.length + 2 > 16_000) continue;
    tree['AGENTS.md'] = tree['AGENTS.md']!.trimEnd() + '\n' + line;
  }
}

async function mapPhase<T, R>(items: readonly T[], width: number, signal: AbortSignal, work: (item: T) => Promise<R>): Promise<R[]> {
  const results = new Array<R>(items.length);
  let next = 0;
  const workers = Array.from({ length: Math.min(items.length, width) }, async () => {
    while (next < items.length) {
      signal.throwIfAborted();
      const index = next++;
      results[index] = await work(items[index]!);
    }
  });
  // Drain the phase before propagating failure, as upstream's executor context does.
  const settled = await Promise.allSettled(workers);
  for (const result of settled) if (result.status === 'rejected') throw result.reason;
  return results;
}

function diversity(trees: Record<string, HarnessTree>): Record<string, number> {
  const names = Object.keys(trees).sort();
  if (names.length < 2) return { n: names.length, n_distinct: names.length, mean_line_jaccard: 1 };
  const sets = names.map(name => new Set(Object.entries(trees[name]!).flatMap(([path, body]) => body.split(/\r?\n/).filter(line => line.trim()).map(line => `${path}:${line}`))));
  const similarities: number[] = [];
  for (let i = 0; i < sets.length; i++) for (let j = i + 1; j < sets.length; j++) {
    const union = new Set([...sets[i]!, ...sets[j]!]);
    const common = [...sets[i]!].filter(line => sets[j]!.has(line)).length;
    similarities.push(union.size ? common / union.size : 1);
  }
  const distinct = new Set(Object.values(trees).map(tree => JSON.stringify(Object.entries(tree).sort(([a], [b]) => a.localeCompare(b)))));
  return { n: names.length, n_distinct: distinct.size, mean_line_jaccard: Math.round(similarities.reduce((a, b) => a + b, 0) / similarities.length * 10_000) / 10_000 };
}

export async function runHarness2(request: Harness2Request, callbacks: Harness2Callbacks): Promise<Harness2Result> {
  request.signal.throwIfAborted();
  if (![1, 2].includes(request.k) || !['parallel', 'sequential'].includes(request.mode)) throw new Error('Unsupported recursion budget or mode');
  const width = request.phaseWidth ?? PHASE_WIDTH;
  if (![1, 2].includes(width)) throw new Error('Unsupported phase width');
  validateFiles(request.base);
  assertNoGraderVerdicts(request.base);
  await callbacks.validate(clone(request.base));
  request.signal.throwIfAborted();
  const baseTree = clone(request.base), mode = request.mode === 'parallel' ? 'agg' : 'seq';
  const library = request.libraryRoot ? new ExperienceLibrary(request.libraryRoot, request.domain) : undefined;
  const libraryEvidence = library ? await library.context() : {};
  request.signal.throwIfAborted();
  const stages: Stage[] = [], candidates: Record<string, Candidate> = {}, bases: Record<string, Candidate> = {};
  const rolloutLabels: string[] = [], sessionLabels: string[] = [], finals: string[] = [];

  const roll = async (label: string, tree: HarnessTree): Promise<Candidate> => {
    request.signal.throwIfAborted();
    try {
      const reply = await callbacks.probe(label, clone(tree), request.prompt);
      request.signal.throwIfAborted();
      if (typeof reply.answer !== 'string' || typeof reply.trajectory !== 'string') throw new Error('Invalid probe output');
      return { tree: clone(tree), answer: reply.answer, trajectory: reply.trajectory, ok: true, delivered: true };
    } catch (error) {
      request.signal.throwIfAborted();
      return { tree: clone(tree), answer: '', trajectory: '', ok: false, delivered: false, error: stageError(error) };
    }
  };
  const rollMany = async (items: { label: string; tree: HarnessTree }[]) => {
    const answers = await mapPhase(items, width, request.signal, item => roll(item.label, item.tree));
    for (const [index, item] of items.entries()) { candidates[item.label] = answers[index]!; rolloutLabels.push(item.label); }
  };
  for (let index = 1; index <= request.k; index++) {
    const label = `base${index}`;
    const result = await roll(label, baseTree);
    // The original bridge's shared base runs are outside recursion's recoverable failure domain.
    if (!result.ok) throw new Error(result.error ?? 'Base probe failed');
    bases[label] = result; candidates[label] = result; rolloutLabels.push(label);
  }

  const runStage = async (label: string, dim: Stage['dim'], job: string, fallback: HarnessTree, pool: Record<string, Candidate> = {}, decisions: Record<string, string> = {}): Promise<Stage> => {
    request.signal.throwIfAborted();
    const initial: Stage = { label, dim, tree: clone(fallback), kept_base: false, fell_back: true, execution_failed: false, error: '', attempts: 0, notes: '', usd: 0, cached: false };
    let context;
    try { context = buildContext(request, bases, { harness: fallback, pool, decisions, library: libraryEvidence }); }
    catch (error) { return { ...initial, error: `could not build evidence: ${stageError(error)}` }; }
    const jobMission = mission(context, job);
    let error = '';
    let spentMicros = 0;
    for (let attempt = 1; attempt <= STAGE_ATTEMPTS; attempt++) {
      request.signal.throwIfAborted();
      let reply: Awaited<ReturnType<Harness2Callbacks['propose']>>;
      try {
        assertNoGraderVerdicts(context.evidence);
        reply = await callbacks.propose(`${label}.a${attempt}`, jobMission + (error ? `\n\n# YOUR PREVIOUS ATTEMPT WAS REJECTED\n\n${error}\n\nFix it in context/harness/ and reply again.` : ''), { ...context.evidence });
        request.signal.throwIfAborted();
        // The bridge rejects a broken transport surface without consuming an edit retry.
        validateFiles(reply.files);
        if (typeof reply.text !== 'string') throw new Error('Invalid proposer text');
      } catch (failure) {
        request.signal.throwIfAborted();
        return { ...initial, attempts: attempt, usd: spentMicros / 1_000_000, execution_failed: true, error: stageError(failure) };
      }
      spentMicros += reply.costMicros ?? 0;
      const tree = clone(reply.files);
      autotrigger(tree);
      try {
        const alwaysOnChars = Array.from(tree['AGENTS.md']!).length;
        if (alwaysOnChars > 16_000) throw new Error(`always-on content is ${alwaysOnChars} chars, over the 16000 cap; Keep conversational context small`);
        await callbacks.validate(clone(tree));
        request.signal.throwIfAborted();
        for (const path of Object.keys(tree)) {
          const name = /^\.agents\/skills\/([^/]+)\/SKILL\.md$/.exec(path)?.[1];
          if (name && !tree['AGENTS.md']!.includes(name)) throw new Error(`Skill ${name} has no trigger in AGENTS.md`);
        }
        assertNoGraderVerdicts(tree);
      } catch (failure) {
        request.signal.throwIfAborted();
        error = stageError(failure);
        // A retry sees the rejected attempt's edits, not a fresh base snapshot.
        for (const key of Object.keys(context.evidence)) if (key.startsWith('harness/')) delete context.evidence[key];
        for (const [path, body] of Object.entries(tree)) context.evidence[`harness/${path}`] = body;
        continue;
      }
      return { ...initial, tree, kept_base: isDeepStrictEqual(tree, fallback), fell_back: false, attempts: attempt, usd: spentMicros / 1_000_000,
        notes: (/<notes>(.*?)<\/notes>/s.exec(reply.text)?.[1] ?? reply.text).trim() };
    }
    return { ...initial, attempts: STAGE_ATTEMPTS, usd: spentMicros / 1_000_000, error };
  };
  const recordStages = (batch: Stage[]) => { for (const stage of batch) { stages.push(stage); sessionLabels.push(stage.label); } };
  const notes = (batch: Stage[]) => Object.fromEntries(batch.map(stage => [stage.label, stage.notes || stage.error]));

  if (request.mode === 'parallel') {
    const specs: { label: string; dim: 'INV' | 'DOM'; index: number }[] = [];
    for (let index = 1; index <= request.k; index++) for (const dim of ['INV', 'DOM'] as const) specs.push({ label: `${dim.toLowerCase()}${index}`, dim, index });
    const batch = await mapPhase(specs, width, request.signal, spec => runStage(spec.label, spec.dim, prompts.propose(spec.dim, request.k, spec.index), baseTree));
    recordStages(batch);
    await rollMany(batch);
    const pool = Object.fromEntries(batch.map(stage => [stage.label, candidates[stage.label]!]));
    const aggregates = await mapPhase(Array.from({ length: request.k }, (_, i) => i + 1), width, request.signal,
      index => runStage(`agg${index}`, 'AGG', prompts.aggregate(request.k, index), baseTree, pool, notes(batch)));
    recordStages(aggregates);
    finals.push(...aggregates.map(stage => stage.label));
    await rollMany(aggregates);
  } else {
    let current = baseTree;
    let history: Record<string, Candidate> = {};
    for (let round = 1; round <= request.k; round++) {
      const batch = await mapPhase(['INV', 'DOM'] as const, width, request.signal,
        dim => runStage(`${dim.toLowerCase()}${round}`, dim, prompts.propose(dim, request.k, round, request.k > 1 ? round : undefined), current, history, notes(stages)));
      recordStages(batch);
      await rollMany(batch);
      const pool = { ...history, ...Object.fromEntries(batch.map(stage => [stage.label, candidates[stage.label]!])) };
      const label = `c${round}`;
      const composed = await runStage(label, 'COMPOSE', request.k === 1 ? prompts.aggregate() : prompts.composeRound(round, request.k), current, pool, notes(stages));
      recordStages([composed]);
      await rollMany([composed]);
      history = { ...pool, [label]: candidates[label]! };
      current = composed.tree;
      finals.push(label);
    }
  }

  if (new Set(rolloutLabels).size !== rolloutLabels.length || new Set(sessionLabels).size !== sessionLabels.length || rolloutLabels.length !== 4 * request.k || sessionLabels.length !== 3 * request.k) throw new Error('Harness² ledger identity failed');
  const ok = Boolean(finals.length) && !stages.some(stage => stage.execution_failed);
  const summary = { mode, task_id: request.taskId, k: request.k, finals, ok,
    fell_back: stages.filter(stage => stage.fell_back).map(stage => stage.label), kept_base: stages.filter(stage => stage.kept_base).map(stage => stage.label),
    stages: stages.map(({ tree: _tree, ...stage }) => stage),
    similarity: request.mode === 'parallel' ? diversity(Object.fromEntries(finals.map(label => [label, candidates[label]!.tree]))) : null,
    ledger: { mode, task_id: request.taskId, k: request.k, R: rolloutLabels.length, expected_R: 4 * request.k, P: sessionLabels.length,
      session_attempts: stages.reduce((sum, stage) => sum + stage.attempts, 0), expected_P: 3 * request.k,
      usd: Math.round(stages.reduce((sum, stage) => sum + stage.usd, 0) * 1_000_000) / 1_000_000, rollout_usd: 0,
      session_usd: Math.round(stages.reduce((sum, stage) => sum + stage.usd, 0) * 1_000_000) / 1_000_000, rollouts: rolloutLabels, sessions: sessionLabels }, errors: [],
  };
  // Preserve the previous Pi bridge's strict gate around upstream recursion fallbacks.
  if (!ok || stages.some(stage => stage.fell_back) || Object.values(candidates).some(candidate => !candidate.ok)) throw new Error('Harness² did not complete every proposal and probe');
  request.signal.throwIfAborted();
  // The Pi adapter uses a mixed pool, including delivered base and intermediate outputs.
  const selection = await selectCandidates(request, { candidates, propose: callbacks.propose });
  const chosen = candidates[selection.label]!;
  await callbacks.validate(clone(chosen.tree));
  request.signal.throwIfAborted();
  const experience = await reflectAdoption(request, { selection, candidates, decisions: notes(stages), propose: callbacks.propose });
  request.signal.throwIfAborted();
  if (library && Object.keys(experience).length) await library.add(request.taskId, chosen.tree, { why: selection.why, label: selection.label, runId: selection.label, experience });
  request.signal.throwIfAborted();
  return { tree: clone(chosen.tree), selectedLabel: selection.label, experience, summary };
}
