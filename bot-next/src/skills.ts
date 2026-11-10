import { createHash, randomUUID } from 'node:crypto';
import { stripTypeScriptTypes } from 'node:module';
import type { DatabaseSync } from 'node:sqlite';
import { isDeepStrictEqual } from 'node:util';
import { CodemodeSandbox } from '@earendil-works/pi-codemode';
import { atomic } from './budget.js';

export type HarnessTree = Record<string, string>;
export const HARNESS2_COMMIT = '6868817518876de8fd170c6a8354daca273b6191';
const scriptPath = /^\.agents\/skills\/[a-z0-9][a-z0-9_-]*\/scripts\/[a-z0-9][a-z0-9_-]*\.(js|ts)$/;
const skillPath = /^\.agents\/skills\/([a-z0-9][a-z0-9_-]*)\/SKILL\.md$/;

/** The editable harness contains data and isolated JS/TS, never host policy or keys. */
export function validateHarness(tree: HarnessTree): void {
  if (!tree || typeof tree !== 'object' || Array.isArray(tree)) throw new Error('Invalid harness');
  const entries = Object.entries(tree);
  if (!entries.length || entries.length > 100 || !tree['AGENTS.md']?.trim()) throw new Error('Invalid harness inventory');
  if (tree['AGENTS.md'].length > 16_000) throw new Error('AGENTS.md exceeds context budget');
  let bytes = 0;
  for (const [path, body] of entries) {
    if (typeof body !== 'string' || (path !== 'AGENTS.md' && !skillPath.test(path) && !scriptPath.test(path))) throw new Error('Path outside editable harness');
    bytes += Buffer.byteLength(body);
    const match = skillPath.exec(path);
    if (match && (!body.startsWith('---\n') || !new RegExp(`^name: ${match[1]}$`, 'm').test(body) || !/^description:\s*\S/m.test(body))) throw new Error('Invalid skill frontmatter');
    if (scriptPath.test(path)) {
      const owner = path.slice(0, path.indexOf('/scripts/')) + '/SKILL.md';
      if (!Object.hasOwn(tree, owner)) throw new Error('Script has no owning skill');
      compileScript(path, body);
    }
  }
  if (bytes > 256_000) throw new Error('Harness content budget exceeded');
}

function canonical(tree: HarnessTree): string {
  validateHarness(tree);
  return JSON.stringify(Object.fromEntries(Object.entries(tree).sort(([a], [b]) => a.localeCompare(b, 'en'))));
}

export function harnessHash(tree: HarnessTree): string {
  return createHash('sha256').update(canonical(tree)).digest('hex');
}

function compileScript(path: string, source: string): string {
  if (Buffer.byteLength(source) > 64_000) throw new Error('Skill script is too large');
  // Only text transformation happens on the host. JS is executed solely in QuickJS.
  if (!path.endsWith('.ts')) return source;
  const wrapped = stripTypeScriptTypes(`async function skill() {\n${source}\n}`, { mode: 'strip' });
  return `return await (${wrapped})();`;
}

let activeWorkers = 0;
export class SkillSandbox {
  async run(tree: HarnessTree, path: string, input: unknown): Promise<unknown> {
    validateHarness(tree);
    if (!scriptPath.test(path) || !Object.hasOwn(tree, path)) throw new Error('Unknown skill script');
    const encoded = JSON.stringify(input);
    if (encoded === undefined || encoded.length > 64_000) throw new Error('Invalid skill input');
    const sandbox = new CodemodeSandbox({
      tools: [], timeoutMs: 2_000, memoryLimitBytes: 16 * 1024 * 1024,
      acquireWorker() {
        if (activeWorkers >= 2) throw new Error('Skill workers busy');
        activeWorkers++;
        return () => { activeWorkers--; };
      },
    });
    try {
      const code = `const input = ${encoded};\n${compileScript(path, tree[path]!)}`;
      const result = await sandbox.execute(code);
      if (!result.ok) throw new Error(`Skill execution failed: ${result.error.kind}`);
      const output = result.value === undefined ? result.output : result.value;
      if (JSON.stringify(output).length > 64_000) throw new Error('Skill output is too large');
      return output;
    } finally { await sandbox.close(); }
  }
}

export type SkillArtifact = { hash: string; tree: HarnessTree; engineCommit: string };
export type SkillCase = { path: string; input: unknown; expected: unknown };
export type QualityCheck = { cases: number; regressions: number; improves: boolean };
export type SkillEvaluation = { id: string; hash: string; passed: boolean; failures: string[] };

/** Versions are immutable. Promotion changes only a chat-scoped pointer using CAS. */
export class SkillStore {
  readonly db: DatabaseSync;
  constructor(db: DatabaseSync) {
    this.db = db;
    db.exec(`CREATE TABLE IF NOT EXISTS skill_artifacts (
      hash TEXT PRIMARY KEY, tree_json TEXT NOT NULL, engine_commit TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS skill_heads (chat_id TEXT PRIMARY KEY, hash TEXT NOT NULL);
    CREATE TABLE IF NOT EXISTS skill_evaluations (
      id TEXT PRIMARY KEY, hash TEXT NOT NULL, passed INTEGER NOT NULL, report_json TEXT NOT NULL
    );
    CREATE TRIGGER IF NOT EXISTS skill_artifacts_immutable_update BEFORE UPDATE ON skill_artifacts BEGIN SELECT RAISE(ABORT,'Immutable skill artifact'); END;
    CREATE TRIGGER IF NOT EXISTS skill_artifacts_immutable_delete BEFORE DELETE ON skill_artifacts BEGIN SELECT RAISE(ABORT,'Immutable skill artifact'); END;`);
  }

  stage(tree: HarnessTree, engineCommit = HARNESS2_COMMIT): SkillArtifact {
    const content = canonical(tree), hash = harnessHash(tree);
    if (engineCommit !== HARNESS2_COMMIT) throw new Error('Unrecognized evolution engine');
    this.db.prepare('INSERT OR IGNORE INTO skill_artifacts VALUES (?, ?, ?)').run(hash, content, engineCommit);
    return this.get(hash)!;
  }

  get(hash: string): SkillArtifact | undefined {
    const row = this.db.prepare('SELECT hash, tree_json, engine_commit FROM skill_artifacts WHERE hash=?').get(hash) as { hash: string; tree_json: string; engine_commit: string } | undefined;
    if (!row) return undefined;
    const tree = JSON.parse(row.tree_json) as HarnessTree;
    if (harnessHash(tree) !== row.hash) throw new Error('Skill artifact integrity failure');
    return { hash: row.hash, tree, engineCommit: row.engine_commit };
  }

  current(chatId: string | number): SkillArtifact | undefined {
    const row = this.db.prepare('SELECT hash FROM skill_heads WHERE chat_id=?').get(String(chatId)) as { hash: string } | undefined;
    return row ? this.get(row.hash) : undefined;
  }

  /** Host fixtures and quality judge are never taken from the candidate's own code. */
  async evaluate(hash: string, cases: SkillCase[], quality: (tree: HarnessTree) => Promise<QualityCheck>, sandbox = new SkillSandbox()): Promise<SkillEvaluation> {
    const artifact = this.get(hash);
    if (!artifact) throw new Error('Unknown skill artifact');
    const failures: string[] = [];
    for (const path of Object.keys(artifact.tree).filter(path => scriptPath.test(path))) {
      if (!cases.some(test => test.path === path)) failures.push(`No host test for ${path}`);
    }
    for (const test of cases) {
      try {
        if (!isDeepStrictEqual(await sandbox.run(artifact.tree, test.path, test.input), test.expected)) failures.push(`Unexpected result: ${test.path}`);
      } catch { failures.push(`Execution failed: ${test.path}`); }
    }
    const score = await quality(artifact.tree);
    if (!Number.isSafeInteger(score.cases) || score.cases < 3 || score.regressions !== 0 || score.improves !== true) failures.push('Independent quality checks did not pass');
    const report: SkillEvaluation = { id: randomUUID(), hash, passed: failures.length === 0, failures };
    this.db.prepare('INSERT INTO skill_evaluations VALUES (?, ?, ?, ?)').run(report.id, hash, report.passed ? 1 : 0, JSON.stringify(report));
    return report;
  }

  promote(chatId: string | number, hash: string, expectedPrevious: string | null, evaluationId: string): SkillArtifact {
    return atomic(this.db, () => {
      const report = this.db.prepare('SELECT hash, passed FROM skill_evaluations WHERE id=?').get(evaluationId) as { hash: string; passed: number } | undefined;
      if (!report || report.hash !== hash || report.passed !== 1) throw new Error('Candidate has no passing host evaluation');
      const artifact = this.get(hash);
      if (!artifact) throw new Error('Unknown candidate');
      const previous = this.current(chatId)?.hash ?? null;
      if (previous !== expectedPrevious) throw new Error('Skill head changed during evaluation');
      this.db.prepare('INSERT INTO skill_heads VALUES (?, ?) ON CONFLICT(chat_id) DO UPDATE SET hash=excluded.hash').run(String(chatId), hash);
      return artifact;
    });
  }
}
