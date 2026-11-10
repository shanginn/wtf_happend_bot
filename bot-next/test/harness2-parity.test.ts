import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import test from 'node:test';
import { Budget } from '../src/budget.js';
import { Harness2Runner, type EvolutionCallbacks, type EvolutionRequest, type EvolutionResult } from '../src/evolution.js';
import { HARNESS2_COMMIT, type HarnessTree } from '../src/skills.js';

type Fingerprint = { sha256: string; chars: number };
type ProbeEvent = { kind: 'probe'; label: string; tree: HarnessTree };
type ProposalEvent = {
  kind: 'propose'; label: string; evidencePaths: string[];
  harness: Record<string, Fingerprint>; pool: Record<string, Fingerprint>; decisions: Record<string, string>;
  candidates?: Record<string, string>; adoption?: unknown;
};
type Scenario = {
  name: string; k: 1 | 2; mode: 'parallel' | 'sequential'; behavior: string;
  request: EvolutionRequest; trace: Array<ProbeEvent | ProposalEvent>;
  result?: EvolutionResult; failure?: { engineError: string; calls: number; costMicros: number };
};
const golden = JSON.parse(await readFile(new URL('./fixtures/harness2-upstream-parity.json', import.meta.url), 'utf8')) as {
  provenance: { commit: string }; scenarios: Scenario[];
};
const digest = (value: string): string => createHash('sha256').update(value).digest('hex');

/** Scripted outputs are independent of the port; the JSON oracle was captured from upstream. */
function scripted(scenario: Scenario): { trace: Array<ProbeEvent | ProposalEvent>; callbacks: EvolutionCallbacks } {
  const trace: Array<ProbeEvent | ProposalEvent> = [];
  const answers: Record<string, string> = {}, seen: Record<string, number> = {};
  return { trace, callbacks: {
    async probe(request) {
      assert.equal(request.effects, 'simulated');
      assert.equal(request.prompt, scenario.request.prompt);
      const answer = digest(request.tree['AGENTS.md']!);
      answers[request.label] = answer;
      trace.push({ kind: 'probe', label: request.label, tree: structuredClone(request.tree) });
      return { answer, trajectory: `[ACTION] Inspect the complete response\n[OBSERVATION] ${answer}`, costMicros: 1 };
    },
    async propose(request) {
      assert.equal(request.effects, 'simulated');
      const evidence = request.evidence;
      const event: ProposalEvent = { kind: 'propose', label: request.label,
        evidencePaths: Object.keys(evidence).sort(), harness: {}, pool: {}, decisions: {} };
      for (const [name, body] of Object.entries(evidence)) {
        if (name.startsWith('harness/')) event.harness[name.slice(8)] = { sha256: digest(body), chars: body.length };
        if (/^pool\/[^/]+\/harness\//.test(name)) event.pool[name] = { sha256: digest(body), chars: body.length };
        if (name.startsWith('decisions/')) event.decisions[name] = body;
      }
      if (request.label.startsWith('select.')) {
        // The selector receives complete answers with anonymous IDs, never source trees,
        // proposer decisions, trajectories, original labels, or an answer grading signal.
        assert.ok(event.evidencePaths.every(name => name === 'task.md' || /^candidates\/[A-Z]+\.md$/.test(name)));
        event.candidates = Object.fromEntries(Object.entries(evidence)
          .filter(([name]) => /^candidates\/[A-Z]+\.md$/.test(name))
          .map(([name, body]) => {
            assert.doesNotMatch(body, /lineage:|\b(?:inv|dom|agg|base|c)[12]\b/);
            return [name, body.split('-->\n')[1]!];
          }));
      }
      if (evidence['adoption.json']) event.adoption = JSON.parse(evidence['adoption.json']);
      trace.push(event);
      if (request.label.startsWith('select.')) {
        if (scenario.behavior === 'invalid-selection') return { text: 'No valid choice block', costMicros: 1 };
        const label = scenario.behavior === 'choose-baseline' ? 'base1' : scenario.mode === 'parallel' ? `agg${scenario.k}` : `c${scenario.k}`;
        const chosen = Object.entries(event.candidates!).find(([, answer]) => answer === answers[label]);
        assert.ok(chosen, 'the desired delivered answer remains available to the selector');
        return { text: `<why>The complete answer meets the task.</why>\n\`\`\`choice\n${chosen[0].slice(11, -3)}\n\`\`\``, costMicros: 1 };
      }
      if (request.label.startsWith('experience.')) return {
        text: '<experience>\ncue: Comparing complete outputs\nlesson: Keep supported changes and retain a strong baseline\nevidence: The selector compared delivered answers\nconfidence: medium\n</experience>\n<edits>Keep the chosen lineage only.</edits>', costMicros: 1,
      };
      const files = Object.fromEntries(Object.entries(evidence).filter(([name]) => name.startsWith('harness/'))
        .map(([name, body]) => [name.slice(8), body]));
      const stage = request.label.replace(/\.a\d+$/, '');
      seen[stage] = (seen[stage] ?? 0) + 1;
      if (scenario.behavior === 'malformed-proposal' && stage === 'inv1') return { text: '<notes>Missing harvested tree.</notes>', costMicros: 1 };
      if (stage === 'inv1' && (scenario.behavior === 'invalid-proposal-always' || (scenario.behavior === 'invalid-proposal-once' && seen[stage] === 1))) {
        return { text: '<notes>Overlong instructions.</notes>', files: { 'AGENTS.md': 'x'.repeat(16001) }, costMicros: 1 };
      }
      if (files['AGENTS.md']!.length > 16000) files['AGENTS.md'] = scenario.request.base['AGENTS.md']!;
      files['AGENTS.md'] += `>${stage}`;
      return { text: `<notes>Adopt ${stage} evidence.</notes>`, files, costMicros: 1 };
    },
  } };
}

for (const scenario of golden.scenarios) test(`Harness² upstream oracle parity: ${scenario.name}`, async () => {
  assert.equal(golden.provenance.commit, HARNESS2_COMMIT);
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 1000), runner = new Harness2Runner(budget);
    const simulation = scripted(scenario);
    if (scenario.result) {
      const result = await runner.run(scenario.request, simulation.callbacks);
      const { engineImplementation: _, ...upstreamFields } = result;
      assert.deepEqual(simulation.trace, scenario.trace, 'upstream callback order, lineage, anonymization, and evidence paths');
      assert.deepEqual(upstreamFields, scenario.result, 'upstream selection, reflection, and complete stage/ledger result');
      const ledger = result.summary.ledger as { R: number; P: number; expected_R: number; expected_P: number };
      assert.equal(ledger.R, 4 * scenario.k);
      assert.equal(ledger.P, 3 * scenario.k);
      assert.equal(ledger.expected_R, ledger.R);
      assert.equal(ledger.expected_P, ledger.P);
    } else {
      await assert.rejects(runner.run(scenario.request, simulation.callbacks));
      assert.deepEqual(simulation.trace, scenario.trace, 'invalid responses follow the upstream retry/fallback boundary');
      assert.equal(db.prepare('SELECT count(*) AS n FROM evolution_runs').get()!.n, 0, 'a fallback is never promoted or cached as success');
    }
    assert.equal(budget.balance().spentMicros, (scenario.result ?? scenario.failure)!.costMicros);
    assert.equal(budget.balance().reservedMicros, 0);
  } finally { db.close(); }
});

test('successful native evolution survives a process restart without another model call or charge', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'harness2-native-restart-'));
  const file = join(directory, 'state.sqlite');
  const scenario = golden.scenarios.find(item => item.name === 'sequential-k2')!;
  let db = new DatabaseSync(file);
  try {
    const expected = await new Harness2Runner(new Budget(db, 1000)).run(scenario.request, scripted(scenario).callbacks);
    db.close(); db = new DatabaseSync(file);
    const budget = new Budget(db, 0);
    const unexpected = async (): Promise<never> => { throw new Error('cached runs must not dispatch'); };
    const cached = await new Harness2Runner(budget).run(scenario.request, { propose: unexpected, probe: unexpected });
    assert.deepEqual(cached, expected);
    assert.equal(budget.balance().spentMicros, expected.costMicros);
    await assert.rejects(new Harness2Runner(budget).run({ ...scenario.request, prompt: 'Different task with reused identity' }, { propose: unexpected, probe: unexpected }), /identity conflict/);
  } finally { db.close(); await rm(directory, { recursive: true, force: true }); }
});

test('deadline abort cannot turn an unknown provider cost into free retries or later adoption', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 1000), runner = new Harness2Runner(budget);
    const request = { ...golden.scenarios[0]!.request, taskId: 'deadline-unknown', timeoutMs: 25 };
    let resolveProbe!: (value: { answer: string; trajectory: string; costMicros: number }) => void;
    let dispatched = 0;
    const callback: EvolutionCallbacks = {
      async probe() { dispatched++; return new Promise(resolve => { resolveProbe = resolve; }); },
      async propose() { dispatched++; throw new Error('aborted run cannot continue'); },
    };
    await assert.rejects(runner.run(request, callback), /deadline/);
    assert.equal(dispatched, 1);
    assert.equal(budget.balance().spentMicros, request.maximumCostMicros);
    resolveProbe({ answer: 'late answer', trajectory: 'late evidence', costMicros: 1 });
    await new Promise<void>(resolve => setImmediate(resolve));
    assert.equal(dispatched, 1);
    assert.equal(db.prepare('SELECT count(*) AS n FROM evolution_runs').get()!.n, 0);
    assert.equal(budget.balance().spentMicros, request.maximumCostMicros);
    await assert.rejects(new Harness2Runner(budget).run(request, callback), /already attempted/);
    assert.equal(dispatched, 1);
  } finally { db.close(); }
});
