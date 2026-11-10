import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import test from 'node:test';
import { Budget } from '../src/budget.js';
import { Harness2Runner, HARNESS2_IMPLEMENTATION, type EvolutionCallbacks } from '../src/evolution.js';
import { HARNESS2_COMMIT, SkillSandbox, SkillStore, validateHarness, type HarnessTree } from '../src/skills.js';

const script = '.agents/skills/ledger/scripts/sum.ts';
const baseline: HarnessTree = {
  'AGENTS.md': 'Use ledger to sum signed amounts accurately.',
  '.agents/skills/ledger/SKILL.md': '---\nname: ledger\ndescription: Sum a ledger.\n---\nRun scripts/sum.ts in the isolated skill tool.',
  [script]: 'const amounts: number[] = input; return amounts.filter(x => x > 0).reduce((a, b) => a + b, 0);',
};

function callbacks(sandbox: SkillSandbox): EvolutionCallbacks {
  return {
    async probe(request) {
      assert.equal(request.effects, 'simulated');
      const answer = String(await sandbox.run(request.tree, script, [100, -20, 5]));
      return { answer, trajectory: `[ACTION] Compute the total\n[OBSERVATION] ${answer}`, costMicros: 1 };
    },
    async propose(request) {
      assert.equal(request.effects, 'simulated');
      if (request.label.startsWith('select.')) {
        const chosen = Object.entries(request.evidence).find(([name, body]) => /^candidates\/[A-Z]+\.md$/.test(name) && /\b85\b/.test(body));
        assert.ok(chosen);
        return { text: `<why>Includes credits.</why>\n\`\`\`choice\n${chosen[0].split('/')[1]!.replace('.md', '')}\n\`\`\``, costMicros: 1 };
      }
      if (request.label.startsWith('experience.')) return {
        text: '<experience>\ncue: Signed ledger amounts\nlesson: Include credits in totals\nevidence: Removing the positive filter changed 105 to 85\nconfidence: high\n</experience>', costMicros: 1,
      };
      const files = Object.fromEntries(Object.entries(request.evidence).filter(([name]) => name.startsWith('harness/')).map(([name, body]) => [name.slice('harness/'.length), body]));
      files[script] = files[script]!.replace('.filter(x => x > 0)', '');
      return { files, text: '<notes>Include negative amounts.</notes>', costMicros: 1 };
    },
  };
}

for (const mode of ['parallel', 'sequential'] as const) test(`TypeScript Harness2 ${mode}: proposals, isolated probes, composition, selection and reflection`, async () => {
  const db = new DatabaseSync(':memory:');
  const root = await mkdtemp(join(tmpdir(), 'harness2-library-test-'));
  try {
    const budget = new Budget(db, 1000);
    const runner = new Harness2Runner(budget);
    const request = { taskId: 'signed-ledger', chatId: 'test-chat', prompt: 'Sum the signed amounts 100, -20, 5.',
      base: baseline, mode, maximumCalls: 12, maximumCostMicros: 120, perCallMaximumMicros: 10, libraryRoot: root };
    const result = await runner.run(request, callbacks(new SkillSandbox()));
    assert.equal(result.engineCommit, HARNESS2_COMMIT);
    assert.equal(result.engineImplementation, HARNESS2_IMPLEMENTATION);
    assert.equal(result.calls, 9);
    assert.equal(result.costMicros, 9);
    assert.equal(result.tree[script]!.includes('.filter('), false);
    assert.equal(result.experience.lesson, 'Include credits in totals');
    assert.equal(budget.balance().spentMicros, 9);
    const again = await runner.run(request, { propose: async () => { throw Error('must not call'); }, probe: async () => { throw Error('must not call'); } });
    assert.deepEqual(again, result);
    assert.equal(budget.balance().spentMicros, 9);
    assert.equal(result.summary.ok, true);
  } finally { db.close(); await rm(root, { recursive: true, force: true }); }
});

test('budget refusal happens before executing a provider callback', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const runner = new Harness2Runner(new Budget(db, 1));
    let calls = 0;
    await assert.rejects(runner.run({ taskId: 'too-expensive', chatId: 1, prompt: 'Hi', base: baseline,
      maximumCalls: 9, maximumCostMicros: 90, perCallMaximumMicros: 10 }, {
      propose: async () => { calls++; throw Error('unexpected'); }, probe: async () => { calls++; throw Error('unexpected'); },
    }), /Budget exhausted/);
    assert.equal(calls, 0);
  } finally { db.close(); }
});

test('authored TS runs inside QuickJS without host process, filesystem or network access', async () => {
  const tree = { ...baseline, [script]: 'return [typeof process, typeof require, typeof fetch, typeof WebAssembly];' };
  assert.deepEqual(await new SkillSandbox().run(tree, script, null), ['undefined', 'undefined', 'undefined', 'undefined']);
  await assert.rejects(new SkillSandbox().run({ ...tree, [script]: 'return await tools.sendMessage({ text: "test" });' }, script, null), /Skill execution failed/);
});

test('run call ceiling stops the upstream loop and accounts for completed calls', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 1000);
    const runner = new Harness2Runner(budget);
    let count = 0;
    await assert.rejects(runner.run({ taskId: 'call-limit', chatId: 1, prompt: 'Compute the total.', base: baseline,
      maximumCalls: 1, maximumCostMicros: 100, perCallMaximumMicros: 10 }, {
      probe: async () => { count++; return { answer: '105', trajectory: 'Computed 105', costMicros: 2 }; },
      propose: async () => { count++; throw new Error('Must not dispatch'); },
    }), /budget exhausted/);
    assert.equal(count, 1);
    assert.equal(budget.balance().spentMicros, 2);
    assert.equal(budget.balance().reservedMicros, 0);
  } finally { db.close(); }
});

test('unconfirmed provider cost cannot release the reserved evolution budget', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 1000);
    const runner = new Harness2Runner(budget);
    await assert.rejects(runner.run({ taskId: 'unknown-cost', chatId: 1, prompt: 'Compute the total.', base: baseline,
      maximumCalls: 9, maximumCostMicros: 100, perCallMaximumMicros: 10 }, {
      probe: async () => { throw new Error('Provider disconnected after dispatch'); },
      propose: async () => { throw new Error('Must not dispatch'); },
    }), /disconnected/);
    assert.equal(budget.balance().spentMicros, 100);
  } finally { db.close(); }
});

test('deadline bounds an uncooperative callback and late completion cannot mutate the settled budget', { timeout: 2000 }, async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const budget = new Budget(db, 1000), runner = new Harness2Runner(budget);
    let finish!: (value: { answer: string; trajectory: string; costMicros: number }) => void;
    let calls = 0;
    const request = { taskId: 'stalled', chatId: 1, prompt: 'Compute the total.', base: baseline,
      maximumCalls: 9, maximumCostMicros: 100, perCallMaximumMicros: 10, timeoutMs: 50 };
    await assert.rejects(runner.run(request, {
      probe: () => { calls++; return new Promise(resolve => { finish = resolve; }); },
      propose: async () => { calls++; throw new Error('Must not dispatch'); },
    }), /deadline/i);
    assert.equal(calls, 1);
    assert.equal(budget.balance().spentMicros, 100);
    finish({ answer: 'late', trajectory: 'late', costMicros: 1 });
    await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(budget.balance().spentMicros, 100);
    assert.equal(db.prepare('SELECT COUNT(*) AS n FROM evolution_runs').get()!.n, 0);
    await assert.rejects(runner.run(request, callbacks(new SkillSandbox())), /already attempted/);
  } finally { db.close(); }
});

test('immutable artifacts require independent checks and compare-and-swap promotion', async () => {
  const db = new DatabaseSync(':memory:');
  try {
    const skills = new SkillStore(db);
    const fixed = skills.stage({ ...baseline, [script]: 'return input.reduce((a, b) => a + b, 0);' });
    const noCases = await skills.evaluate(fixed.hash, [], async () => ({ cases: 3, regressions: 0, improves: true }));
    assert.equal(noCases.passed, false);
    assert.throws(() => skills.promote(1, fixed.hash, null, noCases.id), /no passing/);
    const good = await skills.evaluate(fixed.hash, [{ path: script, input: [1, -2, 3], expected: 2 }], async () => ({ cases: 3, regressions: 0, improves: true }));
    skills.promote(1, fixed.hash, null, good.id);
    assert.equal(skills.current(1)?.hash, fixed.hash);
    assert.equal(skills.current(2), undefined);
    assert.throws(() => skills.promote(1, fixed.hash, null, good.id), /head changed/);
    assert.throws(() => db.prepare('UPDATE skill_artifacts SET tree_json=?').run('{}'), /Immutable/);
    assert.throws(() => validateHarness({ ...baseline, '../outside.ts': 'return 1;' }), /outside editable/);
  } finally { db.close(); }
});
