import { strict as assert } from 'node:assert';
import { mkdtemp, mkdir, readFile, rm, symlink, writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { test } from 'node:test';
import { ExperienceLibrary, domainDirectory, taskSlug } from '../src/harness2/library.js';
import { assignCandidateIds, parseExperience, parseSelection, reflectAdoption, selectCandidates } from '../src/harness2/selection.js';
import type { Candidate, Evidence, Harness2Request } from '../src/harness2/types.js';

const request = (): Harness2Request => ({ taskId: 'task/one', domain: 'group/chat', prompt: 'Give a useful concise answer.',
  base: { 'AGENTS.md': 'Be accurate.' }, k: 1, mode: 'parallel', signal: new AbortController().signal });
const candidate = (answer: string): Candidate => ({ tree: { 'AGENTS.md': `Answer ${answer}` }, answer,
  trajectory: `Observed ${answer}`, ok: true, delivered: true });
const experience = { cue: 'Uncertain context', lesson: 'Ask for missing evidence.', evidence: 'The baseline avoided an invented date.', confidence: 'Limited to this contrast.' };

test('candidate aliases depend on canonical labels and task identity, not insertion order or content', () => {
  const labels = ['base1', 'inv1', 'dom1', 'agg1'];
  const first = assignCandidateIds(labels, request());
  assert.deepEqual(first, assignCandidateIds([...labels].reverse(), request()));
  assert.deepEqual(first, assignCandidateIds(labels, { ...request(), mode: 'agg' }));
  assert.equal(new Set(Object.values(first)).size, labels.length);
  assert.throws(() => assignCandidateIds(['base1', 'base1'], request()), /Invalid candidate labels/);
});

test('selection is blind, includes the delivered baseline and never forces an improvement', async () => {
  const candidates = { base1: candidate('Original delivered answer'), agg1: candidate('New delivered answer'), failed: { ...candidate(''), delivered: false } };
  const expectedMap = assignCandidateIds(['base1', 'agg1'], request());
  let calls = 0;
  const result = await selectCandidates(request(), { candidates, propose: async (label, mission, evidence) => {
    calls++;
    assert.equal(label, 'select.agg1');
    assert.match(mission, /Choose exactly one/);
    assert.equal(Object.keys(evidence).length, 3);
    assert.equal(Object.keys(evidence).some(path => /base1|agg1|failed|trajectory|harness|grade/.test(path)), false);
    const id = Object.entries(expectedMap).find(([, value]) => value === 'base1')![0];
    assert.ok(evidence[`candidates/${id}.md`]!.includes('Original delivered answer'));
    return { text: `<why>The original is clearer.</why>\n\`\`\`choice\n${id}\n\`\`\`` };
  } });
  assert.equal(result.label, 'base1');
  assert.equal(calls, 1);
  assert.equal(result.why, 'The original is clearer.');
});

test('invalid selection fails after one callback; JSON and tagged response envelopes are supported', async () => {
  let calls = 0;
  await assert.rejects(selectCandidates(request(), { candidates: { base1: candidate('One') }, propose: async () => {
    calls++; return { text: 'I choose a nonexistent option' };
  } }), /selector did not choose/);
  assert.equal(calls, 1);
  assert.deepEqual(parseSelection('{"choice":"A","why":"Useful"}', ['A']), { id: 'A', why: 'Useful' });
  assert.deepEqual(parseSelection(JSON.stringify({ text: '<why>Clear</why>\n```choice\nB\n```' }), ['A', 'B']), { id: 'B', why: 'Clear' });
  assert.throws(() => parseSelection('{"choice":"Z"}', ['A']), /available anonymous/);
});

test('reflection receives full contrasts and returns only supported transferable experience fields', async () => {
  const candidates = { base1: candidate('Old answer'), agg1: candidate('New answer') };
  let evidenceSeen: Evidence = {};
  const result = await reflectAdoption(request(), { candidates, decisions: { agg1: 'Combined the useful edits.' },
    selection: { label: 'base1', why: 'Avoided guessing.', idMap: { A: 'base1', B: 'agg1' } },
    propose: async (label, _mission, evidence) => {
      evidenceSeen = evidence;
      assert.equal(label, 'experience.agg1');
      return { text: '<edits>Retained the factual baseline.</edits>\n<experience>\ncue: Uncertain context\nlesson: Ask for missing evidence.\nevidence: The baseline avoided an invented date.\nconfidence: Limited to this contrast.\n</experience>' };
    },
  });
  assert.deepEqual(result, { ...experience, edits: 'Retained the factual baseline.' });
  for (const path of ['rollouts/base1.md', 'rollouts/agg1.md', 'pool/agg1/harness/AGENTS.md', 'decisions/agg1.md', 'base_harness/AGENTS.md', 'adoption.json']) assert.ok(evidenceSeen[path]);
  assert.deepEqual(parseExperience(JSON.stringify({ experience })), experience);
  assert.deepEqual(parseExperience(JSON.stringify({ experience, edits: 'Retained the baseline.' })), { ...experience, edits: 'Retained the baseline.' });
  assert.deepEqual(parseExperience('NO_ENTRY'), {});
  assert.deepEqual(parseExperience('{"lesson":"Not enough supporting fields"}'), {});
  assert.throws(() => parseExperience(JSON.stringify({ ...experience, evidence: '"pass_rate":1' })), /Grader verdict/);
});

test('selection refuses grader payloads before calling the selector', async () => {
  let calls = 0;
  await assert.rejects(selectCandidates(request(), { candidates: { base1: candidate('"test_pass_rate":1') }, propose: async () => {
    calls++; return { text: '```choice\nA\n```' };
  } }), /Grader verdict/);
  assert.equal(calls, 0);
});

test('experience library reads previous bridge layout and cumulatively retains full trees', async () => {
  const root = await mkdtemp(join(tmpdir(), 'harness2-library-'));
  try {
    const legacy = join(root, 'group__chat', 'harnesses', 'old__task');
    await mkdir(join(legacy, 'harness'), { recursive: true });
    await writeFile(join(root, 'group__chat', 'INDEX.md'), '# Prior Python bridge library');
    await writeFile(join(legacy, 'EXPERIENCE.md'), '# old/task\n- **cue**: Prior useful case\n- **lesson**: Original lesson');
    await writeFile(join(legacy, 'harness', 'AGENTS.md'), 'Original full instructions.');
    const library = new ExperienceLibrary(root, 'group/chat');
    assert.equal((await library.context())['harnesses/old__task/harness/AGENTS.md'], 'Original full instructions.');
    const tree = { 'AGENTS.md': 'Updated guidance.', '.agents/skills/summary/SKILL.md': '---\nname: summary\ndescription: Summarize decisions\n---\nUse evidence.',
      '.agents/skills/summary/scripts/shorten.ts': 'return input;' };
    await library.add('next/task', tree, { label: 'base1', runId: 'base1', why: 'Kept the grounded answer.', experience });
    const reopened = new ExperienceLibrary(root, 'group/chat');
    const context = await reopened.context();
    assert.equal(context['harnesses/old__task/harness/AGENTS.md'], 'Original full instructions.');
    assert.equal(context['harnesses/next__task/harness/.agents/skills/summary/scripts/shorten.ts'], 'return input;');
    assert.match(context['INDEX.md']!, /Prior useful case/);
    assert.match(context['INDEX.md']!, /Uncertain context/);
    assert.deepEqual(await reopened.treeOf('next/task'), tree);
    await reopened.retain(['next/task']);
    assert.deepEqual(await reopened.entries(), ['next__task']);
    assert.equal((await reopened.context())['harnesses/old__task/harness/AGENTS.md'], undefined);
  } finally { await rm(root, { recursive: true, force: true }); }
});

test('library rejects unsafe paths and grader leakage and does not retain unsupported experience', async () => {
  const root = await mkdtemp(join(tmpdir(), 'harness2-library-'));
  try {
    const library = new ExperienceLibrary(root, 'domain');
    assert.equal(await library.add('unsupported', { 'AGENTS.md': 'base' }, { experience: { lesson: 'unproven' } }), null);
    assert.deepEqual(await library.context(), {});
    assert.throws(() => domainDirectory('..'), /Invalid library domain/);
    assert.throws(() => taskSlug('..'), /Invalid library task/);
    await assert.rejects(library.add('bad', { '../outside': 'oops' }, { experience }), /Invalid library file path/);
    await assert.rejects(library.add('bad', { 'AGENTS.md': 'deterministic_eval_summary' }, { experience }), /Grader verdict/);
    await library.add('good', { 'AGENTS.md': 'safe' }, { experience });
    const indexBefore = await readFile(join(root, 'domain', 'INDEX.md'), 'utf8');
    await symlink(join(root, 'domain', 'INDEX.md'), join(root, 'domain', 'harnesses', 'good', 'harness', 'leak.md'));
    await assert.rejects(library.context(), /Symlinks/);
    assert.equal(await readFile(join(root, 'domain', 'INDEX.md'), 'utf8'), indexBefore);
  } finally { await rm(root, { recursive: true, force: true }); }
});
