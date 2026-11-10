// Copyright 2026 Google LLC
// SPDX-License-Identifier: Apache-2.0
// Modified: TypeScript port of Harness² library.py at commit
// 6868817518876de8fd170c6a8354daca273b6191. The Pi adapter retains the upstream
// domain/INDEX.md and harnesses/<task>/ layout, with bounded reads and symlink rejection.
import { lstat, mkdir, readFile, readdir, rename, rm, writeFile } from 'node:fs/promises';
import { randomUUID } from 'node:crypto';
import { join, resolve } from 'node:path';
import { assertNoGraderVerdicts } from './context.js';
import type { HarnessTree } from '../skills.js';
import type { Evidence, Experience } from './types.js';

export function domainDirectory(domain: string): string {
  const value = domain.replace(/\s*[/\\]\s*/g, '__').trim() || 'unknown';
  if (value === '.' || value === '..' || value.includes('\0') || Buffer.byteLength(value) > 240) throw new Error('Invalid library domain');
  return value;
}
export function taskSlug(taskId: string): string {
  const value = taskId.replace(/[/\\]/g, '__');
  if (!value || value === '.' || value === '..' || value.includes('\0') || Buffer.byteLength(value) > 240) throw new Error('Invalid library task identity');
  return value;
}
export function hasExperience(experience: Experience | undefined): boolean {
  return Boolean(experience && ['cue', 'lesson', 'evidence'].every(key => typeof experience[key] === 'string' && experience[key].trim()));
}
function relativePath(path: string): string[] {
  const parts = path.split('/');
  if (!path || path.includes('\\') || path.includes('\0') || parts.some(part => !part || part === '.' || part === '..')) throw new Error('Invalid library file path');
  return parts;
}
async function isFile(path: string): Promise<boolean> {
  try { const stat = await lstat(path); if (stat.isSymbolicLink()) throw new Error('Symlinks are not allowed in the experience library'); return stat.isFile(); }
  catch (error) { if ((error as NodeJS.ErrnoException).code === 'ENOENT') return false; throw error; }
}
async function ensureDirectory(path: string): Promise<void> {
  await mkdir(path, { recursive: true, mode: 0o700 });
  const stat = await lstat(path);
  if (!stat.isDirectory() || stat.isSymbolicLink()) throw new Error('Invalid experience library directory');
}
async function childDirectory(root: string, parts: readonly string[]): Promise<string> {
  let path = root;
  await ensureDirectory(path);
  for (const part of parts) { path = join(path, part); await ensureDirectory(path); }
  return path;
}
async function writeOwnedFile(path: string, body: string): Promise<void> {
  const temporary = `${path}.tmp-${randomUUID()}`;
  try {
    await writeFile(temporary, body, { mode: 0o600, flag: 'wx' });
    await rename(temporary, path); // Replaces a stale link itself instead of following it.
  } finally { await rm(temporary, { force: true }); }
}
async function readTree(root: string): Promise<Evidence> {
  const result: Evidence = {};
  let bytes = 0, files = 0;
  async function walk(directory: string, prefix: string): Promise<void> {
    const stat = await lstat(directory);
    if (!stat.isDirectory() || stat.isSymbolicLink()) throw new Error('Invalid experience library directory');
    const names = (await readdir(directory)).sort();
    for (const name of names) {
      const path = join(directory, name), relative = `${prefix}${name}`, stat = await lstat(path);
      if (stat.isSymbolicLink()) throw new Error('Symlinks are not allowed in the experience library');
      if (stat.isDirectory()) await walk(path, `${relative}/`);
      else if (stat.isFile()) {
        bytes += stat.size; files++;
        if (bytes > 16 * 1024 * 1024 || files > 10_000) throw new Error('Experience library exceeds its bounded context; retain fewer entries');
        result[relative] = await readFile(path, 'utf8');
      }
    }
  }
  try { await walk(root, ''); }
  catch (error) { if ((error as NodeJS.ErrnoException).code !== 'ENOENT') throw error; }
  assertNoGraderVerdicts(result);
  return result;
}
function inventory(tree: HarnessTree): string {
  const paths = Object.keys(tree);
  const skills = paths.filter(path => path.startsWith('.agents/skills/') && path.endsWith('/SKILL.md')).length;
  const scripts = paths.filter(path => /\/scripts\/[^/]+\.(ts|js)$/.test(path)).length;
  const parts = [...(skills ? [`${skills} skills`] : []), ...(scripts ? [`${scripts} scripts`] : [])];
  return `${parts.join(', ') || 'always-on edits only'}; ${(tree['AGENTS.md'] ?? '').length.toLocaleString('en-US')} always-on chars`;
}

/** Cumulative same-domain experience, compatible with prior Python bridge version directories. */
export class ExperienceLibrary {
  readonly directory: string;
  constructor(readonly root: string, readonly domain: string) { this.root = resolve(root); this.directory = join(this.root, domainDirectory(domain)); }
  async context(): Promise<Evidence> {
    if (!(await isFile(join(this.directory, 'INDEX.md')))) return {};
    const tree = await readTree(this.directory);
    return Object.keys(tree).some(path => path.endsWith('/EXPERIENCE.md')) ? tree : {};
  }
  async entries(): Promise<string[]> {
    const path = join(this.directory, 'harnesses');
    try {
      const stat = await lstat(path);
      if (!stat.isDirectory() || stat.isSymbolicLink()) throw new Error('Invalid library harness directory');
      const entries = await readdir(path, { withFileTypes: true });
      if (entries.some(entry => entry.isSymbolicLink())) throw new Error('Symlinks are not allowed in the experience library');
      return entries.filter(entry => entry.isDirectory()).map(entry => entry.name).sort();
    } catch (error) { if ((error as NodeJS.ErrnoException).code === 'ENOENT') return []; throw error; }
  }
  async treeOf(taskId: string): Promise<HarnessTree> { return readTree(join(this.directory, 'harnesses', taskSlug(taskId), 'harness')); }
  async add(taskId: string, harness: HarnessTree, options: { why?: string; label?: string; runId?: string; experience?: Experience }): Promise<string | null> {
    if (!hasExperience(options.experience)) return null;
    assertNoGraderVerdicts(harness);
    assertNoGraderVerdicts({ 'EXPERIENCE.md': [...Object.values(options.experience!).filter(value => typeof value === 'string'), options.why ?? ''].join('\n') });
    for (const path of Object.keys(harness)) relativePath(path);
    await childDirectory(this.root, [domainDirectory(this.domain), 'harnesses']);
    const entry = await childDirectory(this.directory, ['harnesses', taskSlug(taskId)]);
    const target = join(entry, 'harness');
    // Learning publishes the enclosing library only after independent validation succeeds.
    await rm(target, { recursive: true, force: true });
    await ensureDirectory(target);
    for (const [path, body] of Object.entries(harness)) {
      const parts = relativePath(path);
      const directory = await childDirectory(target, parts.slice(0, -1));
      await writeOwnedFile(join(directory, parts.at(-1)!), body);
    }
    const exp = options.experience!;
    const lines = [`# ${taskId}`, '', `- adopted: \`${options.label ?? ''}\`${options.runId ? `  (run \`${options.runId}\`)` : ''}`, `- components: ${inventory(harness)}`, ''];
    if (options.why?.trim()) lines.push('## Why this was chosen', options.why.trim(), '');
    if (typeof exp.edits === 'string' && exp.edits.trim()) lines.push('## What each edit did', exp.edits.trim(), '');
    lines.push('## Transferable lesson');
    for (const key of ['cue', 'lesson', 'evidence', 'confidence']) if (typeof exp[key] === 'string' && exp[key].trim()) lines.push(`- **${key}**: ${exp[key].trim()}`);
    lines.push('');
    await writeOwnedFile(join(entry, 'EXPERIENCE.md'), lines.join('\n'));
    await this.rebuildIndex();
    return entry;
  }
  async forget(taskIds: readonly string[]): Promise<void> {
    for (const id of taskIds) await rm(join(this.directory, 'harnesses', taskSlug(id)), { recursive: true, force: true });
    await this.rebuildIndex();
  }
  async retain(taskIds: readonly string[]): Promise<void> {
    const keep = new Set(taskIds.map(taskSlug));
    await this.forget((await this.entries()).filter(name => !keep.has(name)));
  }
  async rebuildIndex(): Promise<string> {
    const lines = [`# Library — ${this.domain}`, '', 'Each entry is an earlier task in THIS domain whose harness was chosen, stored with its FULL component tree and why it was picked. Browse this index, then open the entries that look relevant to the matter in front of you.', ''];
    const names = await this.entries();
    if (!names.length) lines.push('_(empty — this is the first task in this domain)_');
    for (const name of names) {
      const entry = join(this.directory, 'harnesses', name);
      const experiencePath = join(entry, 'EXPERIENCE.md');
      const experience = await isFile(experiencePath) ? await readFile(experiencePath, 'utf8') : '';
      const cue = /^- \*\*cue\*\*:\s*(.*)$/m.exec(experience)?.[1]?.trim();
      const tree = await readTree(join(entry, 'harness'));
      lines.push(`## \`harnesses/${name}/\``, `- harness: \`harnesses/${name}/harness/\` — ${inventory(tree)}`, `- why:     \`harnesses/${name}/EXPERIENCE.md\``);
      if (cue) lines.push(`- cue:     ${cue}`);
      lines.push('');
    }
    const index = join(this.directory, 'INDEX.md'), body = lines.join('\n');
    assertNoGraderVerdicts({ 'INDEX.md': body });
    await childDirectory(this.root, [domainDirectory(this.domain)]);
    await writeOwnedFile(index, body);
    return index;
  }
}
