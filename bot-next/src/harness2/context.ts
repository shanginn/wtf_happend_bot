// Copyright 2026 Google LLC
// SPDX-License-Identifier: Apache-2.0
// Modified: TypeScript port of Harness² commit 6868817518876de8fd170c6a8354daca273b6191.
// Bot evidence stays in memory; generic artifact rendering and benchmark file scanning are omitted.
import type { HarnessTree } from '../skills.js';
import type { Candidate, Evidence, Harness2Request } from './types.js';

export const GRADER_VERDICT_MARKERS = [
  'deterministic_eval_summary', 'deterministic_eval_result', 'judge_scope_and_deterministic_status',
  'Judge findings on the rendered result', '"passed_count"', '"pass_rate"', '"test_pass_rate"',
] as const;

/** The upstream leakage tripwire, extended to TS because TS is executable on this surface. */
export function assertNoGraderVerdicts(tree: Evidence): void {
  for (const [path, body] of Object.entries(tree)) {
    for (const marker of GRADER_VERDICT_MARKERS) {
      if (path.includes(marker) || body.includes(marker)) throw new Error(`Grader verdict would enter model context: ${path}`);
    }
  }
}

export interface ContextTree { evidence: Evidence; layout: string }
export interface ContextOptions {
  harness: HarnessTree;
  pool?: Record<string, Candidate>;
  decisions?: Record<string, string>;
  library?: Evidence;
}

function addTree(evidence: Evidence, prefix: string, tree: Evidence): void {
  for (const [path, body] of Object.entries(tree).sort(([a], [b]) => a.localeCompare(b))) evidence[`${prefix}/${path}`] = body;
}

/** Full-context unified changes preserve all evidence; upstream uses difflib's compact hunks. */
export function treeDiff(reference: HarnessTree, candidate: HarnessTree, label: string): string {
  let diff = '';
  for (const path of [...new Set([...Object.keys(reference), ...Object.keys(candidate)])].sort()) {
    const before = reference[path] ?? '', after = candidate[path] ?? '';
    if (before === after) continue;
    const oldLines = before.split('\n'), newLines = after.split('\n');
    if (!before) oldLines.length = 0;
    else if (oldLines.at(-1) === '') oldLines.pop();
    if (!after) newLines.length = 0;
    else if (newLines.at(-1) === '') newLines.pop();
    diff += `--- current/${path}\n+++ ${label}/${path}\n@@ -${oldLines.length ? 1 : 0},${oldLines.length} +${newLines.length ? 1 : 0},${newLines.length} @@\n`;
    diff += oldLines.map(line => `-${line}\n`).join('') + newLines.map(line => `+${line}\n`).join('');
  }
  return diff;
}

export function buildContext(request: Pick<Harness2Request, 'taskId' | 'prompt'>, rollouts: Record<string, Candidate>, options: ContextOptions): ContextTree {
  const evidence: Evidence = { 'task.md': request.prompt };
  const names = Object.keys(rollouts), good: string[] = [];
  const decisions = Object.entries(options.decisions ?? {});
  for (const [label, notes] of decisions) evidence[`decisions/${label}.md`] = notes || 'No decision notes returned.';
  for (const [label, candidate] of Object.entries(rollouts)) {
    evidence[`rollouts/${label}.md`] = candidate.ok ? candidate.trajectory : `(trajectory unavailable: ${candidate.error ?? 'execution failed'})`;
    if (candidate.ok) good.push(label);
    if (candidate.ok) {
      evidence[`deliverables/${label}/answer.txt`] = candidate.answer;
      evidence[`deliverables/${label}/INDEX.md`] = `# ${label} produced 1 file(s)\n\n- \`answer.txt\`  (${candidate.answer.length.toLocaleString('en-US')} chars extracted)\n\nEach .txt is the artifact converted to text.\n`;
    } else evidence[`deliverables/${label}.md`] = '(deliverable unavailable: execution failed)';
  }
  addTree(evidence, 'harness', options.harness);
  const pool = Object.entries(options.pool ?? {});
  for (const [label, candidate] of pool) {
    addTree(evidence, `pool/${label}/harness`, candidate.tree);
    evidence[`pool/${label}/harness.diff`] = treeDiff(options.harness, candidate.tree, label);
    evidence[`pool/${label}/rollout.md`] = candidate.ok ? candidate.trajectory : `(unavailable: ${candidate.error ?? 'execution failed'})`;
    if (candidate.ok) evidence[`pool/${label}/produced.md`] = candidate.answer || '(nothing produced)';
  }
  if (options.library) addTree(evidence, 'library', options.library);
  assertNoGraderVerdicts(evidence);
  if (names.length && !good.length) throw new Error('No trajectory could be rendered for ANY rollout');
  const layout = ['context/task.md                  the task as the agent received it'];
  if (decisions.length) layout.push('context/decisions/              earlier edits and composition decisions');
  if (good.length) layout.push(`context/rollouts/               FULL trajectories: ${good.slice(0, 6).map(name => `${name}.md`).join(', ')}${good.length > 6 ? ` (+${good.length - 6} more)` : ''}`);
  if (names.length) layout.push('context/deliverables/<label>/    the files each rollout produced, one per artifact (browse INDEX.md for the set)');
  if (Object.keys(options.harness).length) layout.push(`context/harness/                the harness you are editing (${Object.keys(options.harness).length} files)`);
  if (pool.length) layout.push(`context/pool/                   ${pool.length} candidates, each with its harness and its rollout`);
  if (Object.keys(options.library ?? {}).length) layout.push('context/library/                earlier tasks in this area -- browse INDEX.md first, then open what looks relevant');
  return { evidence, layout: layout.join('\n') };
}

export function mission(tree: ContextTree, job: string): string {
  return [job.trim(), '', '# YOUR EVIDENCE', '',
    'It is supplied as the complete context/ evidence map. Read it -- do not answer from the mission alone.', '', tree.layout, '',
    'Every trajectory EVENT is present: the trajectory was not clipped to fit this mission. An individual observation may contain an explicit renderer-truncation marker; that marker is ours, not behavior by the solver. Do not diagnose it as a solver failure.', '',
    'Read task.md and the current harness first. Inventory every available rollout or candidate and inspect each at least once before deciding. For a candidate, compare what changed from its editing baseline, what behavior changed in its rollout, what improved, and what regressed or remained unresolved. Then deep-read the evidence relevant to any edit. Cite the specific trajectory moment behind every change; one you cannot trace to a moment is speculation.',
  ].join('\n');
}
