import test from 'node:test';
import assert from 'node:assert/strict';
import { DatabaseSync } from 'node:sqlite';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { acquireOwner } from '../src/main.js';

const exec = promisify(execFile);
const root = fileURLToPath(new URL('../', import.meta.url));

test('CLI inventory/prepare do not initialize an empty spending limit; owner lock also protects maintenance', { timeout: 20_000 }, async () => {
  const directory = await mkdtemp(join(tmpdir(), 'wtf-cli-'));
  const source = join(directory, 'source.jsonl');
  await writeFile(source, JSON.stringify({ id: '-100:1', scope: '-100', authorId: '7', text: 'We meet on Friday', occurredAt: '2026-11-01T00:00:00Z' }) + '\n');
  const cli = (...args: string[]) => exec(process.execPath, ['--import', 'tsx', 'src/cli.ts', ...args], {
    cwd: root, env: { ...process.env, BOT_DATA_DIR: directory, BOT_BUDGET_USD: '', PI_PROVIDER: 'offline', PI_MODEL: 'fixture' }, timeout: 10_000,
  });
  try {
    assert.equal(JSON.parse((await cli('migrate', 'inventory', '--source', source)).stdout).records, 1);
    const prepared = JSON.parse((await cli('migrate', 'prepare', '--source', source, '--chat', '-100', '--until', '2026-11-06', '--max-cost-usd', '1')).stdout);
    assert.equal(prepared.status, 'prepared');
    const db = new DatabaseSync(join(directory, 'bot.sqlite'));
    try { assert.equal(db.prepare("SELECT 1 FROM sqlite_master WHERE name='budget_settings'").get(), undefined); }
    finally { db.close(); }
    const lock = acquireOwner(directory);
    try { await assert.rejects(cli('budget', 'fund', '--usd', '1'), /Another bot process/); }
    finally { lock.close(); }
    const funded = JSON.parse((await cli('budget', 'fund', '--usd', '1')).stdout);
    assert.equal(funded.limitMicros, 1_000_000);
    assert.equal(funded.availableMicros, 1_000_000);
  } finally { await rm(directory, { recursive: true, force: true }); }
});
