import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { access, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { DatabaseSync } from 'node:sqlite';
import test from 'node:test';
import { Budget } from '../src/budget.js';
import { Learning, type LearningRuntime } from '../src/learning.js';
import { Store } from '../src/store.js';
import { HARNESS2_COMMIT, type HarnessTree } from '../src/skills.js';
import type { EvolutionRequest, EvolutionResult } from '../src/evolution.js';

const script = '.agents/skills/sum/scripts/add.ts';
const candidate: HarnessTree = {
  'AGENTS.md': 'Use sum to add numbers. PRIVATE_BODY_MARKER',
  '.agents/skills/sum/SKILL.md': '---\nname: sum\ndescription: Add a list of signed numbers.\n---\nPRIVATE_IMPLEMENTATION_GUIDANCE',
  [script]: '// SECRET_IMPLEMENTATION_MARKER\nreturn input.reduce((a: number, b: number) => a + b, 0);',
};
const digest = (value: string) => createHash('sha256').update(value).digest('hex');
const goodCases = [{ path: script, input: [1, 2], expected: 3 }, { path: script, input: [-1, 1], expected: 0 }];

class FakeRuntime implements LearningRuntime {
  calls: { prompt: string; limit: number; signal?: AbortSignal }[] = [];
  passes = true;
  cases = goodCases;
  cost = 1;
  hangs = false;
  quote() { return 10; }
  async probe(prompt: string, limit: number, signal?: AbortSignal) {
    this.calls.push({ prompt, limit, signal });
    if (this.hangs) return await new Promise<{ answer: string; costMicros: number }>(() => {});
    if (prompt.startsWith('HOST_INDEPENDENT_FIXTURES')) return { answer: JSON.stringify({ cases: this.cases }), costMicros: this.cost };
    if (prompt.startsWith('HOST_BLIND_QUALITY_JUDGE')) return { answer: JSON.stringify({ cases: [
      { preferred: 'B', acceptableA: true, acceptableB: this.passes },
      { preferred: 'A', acceptableA: this.passes, acceptableB: true },
      { preferred: 'tie', acceptableA: true, acceptableB: true },
    ] }), costMicros: this.cost };
    return { answer: 'A plausible conversational response.', costMicros: this.cost };
  }
}

async function setup(tree: HarnessTree = candidate, spendCeiling = false) {
  const directory = await mkdtemp(join(tmpdir(), 'wtf-learning-test-'));
  const db = new DatabaseSync(':memory:');
  const store = new Store(db), budget = new Budget(db, 10_000);
  const previousLibraries: string[] = [];
  let runnerCalls = 0;
  const runner = { async run(request: EvolutionRequest): Promise<EvolutionResult> {
    runnerCalls++;
    assert.ok(request.libraryRoot?.includes('harness2-staging'));
    const file = join(request.libraryRoot!, 'experience.txt');
    let previous = '';
    try { previous = await readFile(file, 'utf8'); } catch {}
    previousLibraries.push(previous);
    await writeFile(file, previous + request.taskId + '\n');
    const op = `fake-upstream:${request.taskId}`;
    budget.reserve(op, request.chatId, request.maximumCostMicros);
    const cost = spendCeiling ? request.maximumCostMicros : 9;
    budget.settle(op, cost);
    return { engineCommit: HARNESS2_COMMIT, tree: { ...tree, 'AGENTS.md': tree['AGENTS.md'] + '\n' + request.taskId },
      selectedLabel: 'agg1', experience: { lesson: 'test lesson' }, summary: { ok: true }, costMicros: cost, calls: 9 };
  } };
  const learning = new Learning(store, budget, directory, { runner, maximumCostMicros: 200 });
  return { directory, db, store, budget, runner, learning, previousLibraries, runnerCalls: () => runnerCalls,
    async close() { db.close(); await rm(directory, { recursive: true, force: true }); } };
}

test('failed external gate leaves production skill and cumulative library unchanged', async () => {
  const env = await setup();
  try {
    const runtime = new FakeRuntime();
    const first = await env.learning.evolve(runtime, 'chat', 'Create a signed sum helper.', 'first');
    assert.equal(first.activated, true);
    const pointer = env.store.get('learning:library:chat')!;
    const library = join(env.directory, 'harness2-library', digest('chat'), pointer, 'experience.txt');
    assert.equal(await readFile(library, 'utf8'), 'first\n');
    runtime.passes = false;
    const rejected = await env.learning.evolve(runtime, 'chat', 'Improve it.', 'rejected');
    assert.equal(rejected.activated, false);
    assert.equal(env.learning.skills.current('chat')?.hash, first.hash);
    assert.equal(env.store.get('learning:library:chat'), pointer);
    assert.equal(await readFile(library, 'utf8'), 'first\n');
    assert.deepEqual(await readdir(join(env.directory, 'harness2-staging')), []);
    runtime.passes = true;
    const third = await env.learning.evolve(runtime, 'chat', 'Improve it further.', 'third');
    assert.equal(third.activated, true);
    assert.deepEqual(env.previousLibraries, ['', 'first\n', 'first\n']);
    const latest = join(env.directory, 'harness2-library', digest('chat'), env.store.get('learning:library:chat')!, 'experience.txt');
    assert.equal(await readFile(latest, 'utf8'), 'first\nthird\n');
    const count = runtime.calls.length;
    assert.deepEqual(await env.learning.evolve(runtime, 'chat', 'Improve it further.', 'third'), third);
    assert.equal(runtime.calls.length, count);
    assert.equal(env.runnerCalls(), 3);
  } finally { await env.close(); }
});

test('independent test author gets descriptions, never implementation, bodies or held-out answers', async () => {
  const env = await setup();
  try {
    const runtime = new FakeRuntime();
    await env.learning.evolve(runtime, 'chat', 'Sum signed inputs.', 'descriptions');
    const author = runtime.calls.find(c => c.prompt.startsWith('HOST_INDEPENDENT_FIXTURES'))!.prompt;
    assert.ok(author.includes('Add a list of signed numbers.'));
    for (const marker of ['SECRET_IMPLEMENTATION', 'PRIVATE_BODY', 'PRIVATE_IMPLEMENTATION', 'переезжал', 'пятницу', 'сонного кота']) assert.equal(author.includes(marker), false);
    assert.ok(runtime.calls.every(c => c.signal instanceof AbortSignal));
    assert.equal(runtime.calls.length, 8);
    assert.equal(env.budget.balance().spentMicros, 17);
    runtime.cases = [goodCases[0]!, goodCases[0]!];
    await assert.rejects(env.learning.evolve(runtime, 'chat', 'Improve sum.', 'duplicate-fixtures'), /two distinct/);
    assert.equal(env.store.get('learning:library:chat'), digest('chat\0descriptions'));
  } finally { await env.close(); }
});

test('whole evolution cap includes upstream, independent fixtures and blind quality calls', async () => {
  const env = await setup(candidate, true);
  try {
    const runtime = new FakeRuntime(); runtime.cost = 10;
    const result = await env.learning.evolve(runtime, 'chat', 'Sum signed inputs.', 'full-cap');
    assert.equal(result.costMicros, 200);
    assert.equal(result.maximumCostMicros, 200);
    assert.equal(env.budget.balance().spentMicros, 200);
    assert.equal(env.budget.balance().reservedMicros, 0);
    const tooSmall = new Learning(env.store, env.budget, env.directory, { runner: env.runner, maximumCostMicros: 169 });
    const before = runtime.calls.length;
    await assert.rejects(tooSmall.evolve(runtime, 'chat', 'Improve.', 'too-small'), /complete evolution/);
    assert.equal(runtime.calls.length, before);
  } finally { await env.close(); }
});

test('deadline aborts a stalled quality provider and never promotes its candidate', async () => {
  const env = await setup();
  try {
    const learning = new Learning(env.store, env.budget, env.directory, { runner: env.runner, maximumCostMicros: 200, timeoutMs: 200 });
    const runtime = new FakeRuntime(); runtime.hangs = true;
    await assert.rejects(learning.evolve(runtime, 'chat', 'Sum signed inputs.', 'timeout'), /deadline/);
    assert.equal(learning.skills.current('chat'), undefined);
    assert.equal(env.store.get('learning:library:chat'), undefined);
    assert.equal(env.budget.balance().reservedMicros, 0);
    assert.equal(env.budget.balance().spentMicros, 19);
    assert.ok(runtime.calls[0]?.signal?.aborted);
    assert.deepEqual(await readdir(join(env.directory, 'harness2-staging')), []);
    await assert.rejects(access(join(env.directory, 'harness2-library')));
  } finally { await env.close(); }
});

test('Learning speaks native TypeScript Harness2 editor, selection and reflection JSON protocols', async () => {
  const directory = await mkdtemp(join(tmpdir(), 'wtf-learning-real-engine-'));
  const db = new DatabaseSync(':memory:');
  try {
    const store = new Store(db), budget = new Budget(db, 10_000);
    const learning = new Learning(store, budget, directory, { maximumCostMicros: 200 });
    const stages: string[] = [];
    class EditorRuntime extends FakeRuntime {
      override async probe(prompt: string, maximum: number, signal?: AbortSignal) {
        if (!prompt.startsWith('HOST_HARNESS2_')) return super.probe(prompt, maximum, signal);
        assert.ok(signal);
        for (const secret of ['я не переезжал', 'Что мы решили про пятницу', 'сонного кота']) assert.equal(prompt.includes(secret), false);
        const phase = prompt.split('\n')[0]!;
        stages.push(phase);
        if (phase === 'HOST_HARNESS2_EDIT') return { answer: JSON.stringify({ text: '<notes>Add the requested signed sum helper.</notes>', files: candidate }), costMicros: 1 };
        if (phase === 'HOST_HARNESS2_PROBE') return { answer: prompt.includes('Use sum') ? 'GOOD_CANDIDATE' : 'BASELINE_RESULT', costMicros: 1 };
        if (phase === 'HOST_HARNESS2_SELECTION') {
          const evidence = JSON.parse(prompt.slice(prompt.lastIndexOf('EVIDENCE:\n') + 'EVIDENCE:\n'.length)) as Record<string, string>;
          const winner = Object.entries(evidence).find(([path, content]) => /^candidates\/[A-Z]+\.md$/.test(path) && content.includes('GOOD_CANDIDATE'));
          assert.ok(winner);
          const choice = winner[0].split('/')[1]!.replace('.md', '');
          return { answer: JSON.stringify({ text: `<why>A useful helper is present.</why>\n\`\`\`choice\n${choice}\n\`\`\`` }), costMicros: 1 };
        }
        assert.equal(phase, 'HOST_HARNESS2_REFLECTION');
        return { answer: JSON.stringify({ text: '<experience>\ncue: Summing signed values\nlesson: Use the isolated sum helper\nevidence: Candidate produced GOOD_CANDIDATE where base lacked the helper\nconfidence: limited to this case\n</experience>' }), costMicros: 1 };
      }
    }
    const result = await learning.evolve(new EditorRuntime(), 'chat', 'Add a signed sum helper.', 'actual-engine');
    assert.equal(result.activated, true);
    assert.equal(result.costMicros, 17);
    assert.equal(stages.length, 9);
    assert.equal(stages.filter(s => s === 'HOST_HARNESS2_EDIT').length, 3);
    assert.ok(stages.includes('HOST_HARNESS2_SELECTION'));
    assert.ok(stages.includes('HOST_HARNESS2_REFLECTION'));
    const index = join(directory, 'harness2-library', digest('chat'), store.get('learning:library:chat')!, digest(JSON.stringify('chat')), 'INDEX.md');
    assert.ok((await readFile(index, 'utf8')).includes('Summing signed values'));
  } finally { db.close(); await rm(directory, { recursive: true, force: true }); }
});

test('constructing Learning does not interrupt another process active run', async () => {
  const env = await setup();
  try {
    env.store.db.prepare("INSERT INTO learning_runs VALUES ('active', 'hash', 'running', NULL)").run();
    const second = new Learning(env.store, env.budget, env.directory, { runner: env.runner });
    assert.equal(env.store.db.prepare("SELECT status FROM learning_runs WHERE id='active'").get()!.status, 'running');
    second.recoverInterrupted();
    assert.equal(env.store.db.prepare("SELECT status FROM learning_runs WHERE id='active'").get()!.status, 'unknown');
  } finally { await env.close(); }
});

test('passing conversational judgement cannot activate a broken executable skill', async () => {
  const env = await setup({ ...candidate, [script]: 'return 3;' });
  try {
    const runtime = new FakeRuntime();
    const result = await env.learning.evolve(runtime, 'chat', 'Add signed values.', 'wrong-code');
    assert.equal(result.activated, false);
    assert.ok(result.failures?.some(failure => failure.startsWith('Unexpected result:')));
    assert.equal(env.learning.skills.current('chat'), undefined);
    assert.equal(env.store.get('learning:library:chat'), undefined);
    assert.deepEqual(await readdir(join(env.directory, 'harness2-staging')), []);
  } finally { await env.close(); }
});
