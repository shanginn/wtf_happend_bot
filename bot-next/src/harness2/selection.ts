// Copyright 2026 Google LLC
// SPDX-License-Identifier: Apache-2.0
// Modified: TypeScript port of Harness² selection.py at commit
// 6868817518876de8fd170c6a8354daca273b6191 for the Pi group-chat adapter.
// Evidence is in memory; selector failures reject as the previous strict bridge did.
import { createHash } from 'node:crypto';
import { assertNoGraderVerdicts, buildContext } from './context.js';
import * as prompts from './prompts.js';
import type { Candidate, Evidence, Experience, Harness2Request, Propose, Selection } from './types.js';

/** CPython's integer-seeded MT19937 and getrandbits-based shuffle, for stable upstream IDs. */
class PythonRandom {
  private state = new Uint32Array(624);
  private index = 624;
  constructor(seed: bigint) {
    const keys: number[] = [];
    do { keys.push(Number(seed & 0xffffffffn)); seed >>= 32n; } while (seed);
    this.state[0] = 19650218;
    for (let i = 1; i < 624; i++) {
      const prior = this.state[i - 1]!;
      this.state[i] = (Math.imul(prior ^ (prior >>> 30), 1812433253) + i) >>> 0;
    }
    let i = 1, j = 0;
    for (let count = Math.max(624, keys.length); count; count--) {
      const prior = this.state[i - 1]!;
      this.state[i] = ((this.state[i]! ^ Math.imul(prior ^ (prior >>> 30), 1664525)) + keys[j]! + j) >>> 0;
      if (++i >= 624) { this.state[0] = this.state[623]!; i = 1; }
      if (++j >= keys.length) j = 0;
    }
    for (let count = 623; count; count--) {
      const prior = this.state[i - 1]!;
      this.state[i] = ((this.state[i]! ^ Math.imul(prior ^ (prior >>> 30), 1566083941)) - i) >>> 0;
      if (++i >= 624) { this.state[0] = this.state[623]!; i = 1; }
    }
    this.state[0] = 0x80000000;
  }
  private uint32(): number {
    if (this.index >= 624) {
      for (let i = 0; i < 624; i++) {
        const y = (this.state[i]! & 0x80000000) | (this.state[(i + 1) % 624]! & 0x7fffffff);
        this.state[i] = this.state[(i + 397) % 624]! ^ (y >>> 1) ^ ((y & 1) ? 0x9908b0df : 0);
      }
      this.index = 0;
    }
    let y = this.state[this.index++]!;
    y ^= y >>> 11;
    y ^= (y << 7) & 0x9d2c5680;
    y ^= (y << 15) & 0xefc60000;
    y ^= y >>> 18;
    return y >>> 0;
  }
  below(n: number): number {
    const bits = Math.floor(Math.log2(n)) + 1;
    let value: number;
    do { value = this.uint32() >>> (32 - bits); } while (value >= n);
    return value;
  }
}

function anonymousId(index: number): string {
  let result = '', n = index + 1;
  while (n) { const remainder = (n - 1) % 26; result = String.fromCharCode(65 + remainder) + result; n = Math.floor((n - 1) / 26); }
  return result;
}
const modeToken = (mode: Harness2Request['mode'] | 'agg' | 'seq') => mode === 'parallel' ? 'agg' : mode === 'sequential' ? 'seq' : mode;

/** Labels alone determine aliases. Candidate text, grades and outcomes cannot affect the shuffle. */
export function assignCandidateIds(labels: readonly string[], request: Pick<Harness2Request, 'taskId' | 'k'> & { mode: Harness2Request['mode'] | 'agg' | 'seq' }): Record<string, string> {
  if (new Set(labels).size !== labels.length || labels.length > 10_000) throw new Error('Invalid candidate labels');
  const hash = createHash('sha1').update(`${request.taskId}|${request.k}|${modeToken(request.mode)}`).digest('hex').slice(0, 12);
  const random = new PythonRandom(BigInt(`0x${hash}`));
  const ordered = [...labels].sort();
  for (let i = ordered.length - 1; i > 0; i--) { const j = random.below(i + 1); [ordered[i], ordered[j]] = [ordered[j]!, ordered[i]!]; }
  return Object.fromEntries(ordered.map((label, i) => [anonymousId(i), label]));
}

function jsonReply(text: string): Record<string, unknown> | undefined {
  try {
    const value: unknown = JSON.parse(text.trim().replace(/^```(?:json)?\s*/, '').replace(/\s*```$/, ''));
    if (value && typeof value === 'object' && !Array.isArray(value)) return value as Record<string, unknown>;
  } catch { /* The upstream response is tagged text, not necessarily JSON. */ }
  return undefined;
}

/** Accept upstream choice tags and the Pi adapter's JSON envelope without another model call. */
export function parseSelection(text: string, ids: readonly string[]): { id: string; why: string } {
  const parsed = jsonReply(text);
  const body = typeof parsed?.text === 'string' ? parsed.text : text;
  const choice = typeof parsed?.choice === 'string' ? parsed.choice.trim()
    : /```choice\s*\n?\s*([A-Za-z0-9_-]+)\s*\n?\s*```/s.exec(body)?.[1]?.trim();
  if (!choice || !ids.includes(choice)) throw new Error('Harness² selector did not choose an available anonymous candidate');
  const why = typeof parsed?.why === 'string' ? parsed.why : /<why>(.*?)<\/why>/s.exec(body)?.[1] ?? '';
  return { id: choice, why: why.trim().slice(0, 2000) };
}

export async function selectCandidates(request: Harness2Request, options: { candidates: Record<string, Candidate>; propose: Propose }): Promise<Selection> {
  request.signal.throwIfAborted();
  const pool = Object.fromEntries(Object.entries(options.candidates).filter(([, candidate]) => candidate.ok && candidate.delivered && candidate.answer.trim()));
  if (!Object.keys(pool).length) throw new Error('Harness² selection has no delivered candidate');
  const idMap = assignCandidateIds(Object.keys(pool), request);
  const evidence: Evidence = { 'task.md': request.prompt };
  for (const [id, label] of Object.entries(idMap)) {
    const answer = pool[label]!.answer;
    evidence[`candidates/${id}.md`] = `<!-- candidate ${id}: ${answer.length} chars, ${answer.split('\n').length} lines. COMPLETE -- nothing trimmed. -->\n${answer}`;
  }
  assertNoGraderVerdicts(evidence);
  const ids = Object.keys(idMap).sort();
  const mission = `${prompts.select(ids.length, 'mixed')}\n\n# YOUR EVIDENCE\n\nRead task.md and every candidates/<id>.md before choosing. Evidence is supplied as an exact file map. Choose exactly one of: ${ids.join(', ')}.\nReturn <why>your reason</why> and a fenced choice block containing only the chosen ID.`;
  // One call, as upstream run_selection: the previous strict Pi bridge rejected its fallback.
  const reply = await options.propose(`select.${modeToken(request.mode)}${request.k}`, mission, evidence);
  request.signal.throwIfAborted();
  const parsed = parseSelection(reply.text, ids);
  return { label: idMap[parsed.id]!, why: parsed.why, idMap };
}

export function parseExperience(text: string): Experience {
  const parsed = jsonReply(text);
  const body = typeof parsed?.text === 'string' ? parsed.text : text;
  const fields: Record<string, string> = {};
  const nested = parsed?.experience && typeof parsed.experience === 'object' && !Array.isArray(parsed.experience)
    ? parsed.experience as Record<string, unknown> : parsed;
  if (nested) for (const key of ['cue', 'lesson', 'evidence', 'confidence', 'edits']) {
    if (typeof nested[key] === 'string' && nested[key].trim()) fields[key] = nested[key].trim();
  }
  if (typeof parsed?.edits === 'string' && parsed.edits.trim()) fields.edits = parsed.edits.trim();
  const tagged = /<experience>(.*?)<\/experience>/s.exec(body)?.[1];
  if (tagged) for (const line of tagged.trim().split('\n')) {
    const colon = line.indexOf(':');
    if (colon < 0) continue;
    const key = line.slice(0, colon).trim().toLowerCase();
    if (['cue', 'lesson', 'evidence', 'confidence'].includes(key)) fields[key] = line.slice(colon + 1).trim();
  }
  const edits = /<edits>(.*?)<\/edits>/s.exec(body)?.[1]?.trim();
  if (edits) fields.edits = edits;
  if (!fields.cue || !fields.lesson || !fields.evidence) return {};
  assertNoGraderVerdicts({ 'EXPERIENCE.md': Object.values(fields).join('\n') });
  return fields;
}

export async function reflectAdoption(request: Harness2Request, options: {
  selection: Selection; candidates: Record<string, Candidate>; decisions: Record<string, string>; propose: Propose;
}): Promise<Experience> {
  request.signal.throwIfAborted();
  const chosen = options.candidates[options.selection.label];
  if (!chosen) return {};
  const pool = Object.fromEntries(Object.entries(options.candidates).filter(([label]) => !label.startsWith('base')));
  const built = buildContext(request, options.candidates, { harness: chosen.tree, pool, decisions: options.decisions });
  const evidence = { ...built.evidence };
  for (const [path, body] of Object.entries(request.base)) evidence[`base_harness/${path}`] = body;
  evidence['adoption.json'] = JSON.stringify({ chosen_label: options.selection.label, candidate_ids: options.selection.idMap, reason: options.selection.why }, null, 2);
  assertNoGraderVerdicts({ ...evidence, 'selection_reason.md': options.selection.why });
  const mission = `${prompts.experience()}\n\n# WHAT HAPPENED\n\nHarness ${options.selection.label} was adopted. Selection reasoning:\n${options.selection.why}\n\nCompare harness/ with base_harness/ and pool/. Read adoption.json, the decisions and execution contrasts before writing.\n\n${built.layout}`;
  let reply: Awaited<ReturnType<Propose>>;
  try { reply = await options.propose(`experience.${modeToken(request.mode)}${request.k}`, mission, evidence); }
  catch (error) { request.signal.throwIfAborted(); return {}; }
  request.signal.throwIfAborted();
  return parseExperience(reply.text);
}
