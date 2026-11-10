import { strict as assert } from "node:assert";
import { mkdtemp, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { DatabaseSync } from "node:sqlite";
import { test } from "node:test";
import { MemoryStore } from "../src/memory.js";
import { inventory, MigrationStore, type ExtractRequest, type KnowledgeExtractor, type MigrationReview, runMigrationCli } from "../src/migrate.js";

const records = [
  { id: "chat:1", scope: "chat", authorId: "user:1", text: "I maintain the garden. Unneeded raw tail.", occurredAt: "2026-01-01T00:00:00Z" },
  { id: "chat:2", scope: "chat", authorId: "user:2", text: "I prefer tea.", occurredAt: "2026-01-02T00:00:00Z" },
  { id: "private:1", scope: "private", authorId: "user:1", text: "Private content", occurredAt: "2026-01-01T00:00:00Z" },
];

async function fixture() {
  const directory = await mkdtemp(join(tmpdir(), "wtf-migration-"));
  const path = join(directory, "legacy.jsonl");
  const db = new DatabaseSync(join(directory, "state.sqlite"));
  await writeFile(path, records.map((value) => JSON.stringify(value)).join("\n"));
  const memory = new MemoryStore(db);
  const migration = new MigrationStore(db, memory);
  return { directory, path, db, memory, migration, async close() { db.close(); await rm(directory, { recursive: true, force: true }); } };
}
const settings = { scope: "chat", cutoff: "2026-02-01T00:00:00Z", maxCostUsd: 1,
  maxChunkCostUsd: 0.1, chunkSize: 1, extractorVersion: "test-extractor-v1", policyVersion: "facts-v1" };
function extraction(request: ExtractRequest) {
  return { costUsd: 0.01, knowledge: request.messages.map((message) => ({
    subject: message.authorId, text: message.id === "chat:1" ? "Maintains the garden" : "Prefers tea",
    sources: [{ id: message.id, quote: message.text.split(".")[0]! }],
  })) };
}

test("staging is bounded, source-grounded and does not import raw history; apply is idempotent", async () => {
  const f = await fixture();
  try {
    assert.equal((await inventory(f.path)).scopes.chat?.messages, 2);
    const first = await f.migration.prepare({ ...settings, path: f.path });
    const second = await f.migration.prepare({ ...settings, path: f.path });
    assert.equal(first.runId, second.runId);
    let calls = 0;
    const extractor: KnowledgeExtractor = async (request) => { calls++; return extraction(request); };
    const completed = await f.migration.resume(first.runId, extractor);
    assert.equal(completed.status, "ready");
    assert.equal(calls, 2);
    assert.equal(completed.costUsd, 0.02);
    assert.deepEqual(f.memory.recall({ scope: "chat" }), []);
    const staged = JSON.stringify(f.db.prepare("SELECT * FROM bot_migration_chunks").all());
    assert.equal(staged.includes("Unneeded raw tail"), false);
    assert.equal(staged.includes("Private content"), false);
    await f.migration.resume(first.runId, extractor);
    assert.equal(calls, 2);
    assert.equal(f.migration.apply(first.runId).appliedCount, 2);
    assert.equal(f.migration.apply(first.runId).appliedCount, 2);
    assert.equal(f.memory.recall({ scope: "chat" }).length, 2);
  } finally { await f.close(); }
});

test("unknown paid extraction holds its budget and is not automatically replayed", async () => {
  const f = await fixture();
  try {
    const prepared = await f.migration.prepare({ ...settings, path: f.path });
    let calls = 0;
    await assert.rejects(f.migration.resume(prepared.runId, async () => { calls++; throw new Error("Lost provider response"); }), /outcome unknown/);
    const report = await new MigrationStore(f.db).resume(prepared.runId, async (request) => { calls++; return extraction(request); });
    assert.equal(calls, 1);
    assert.equal(report.chunks.unknown, 1);
    assert.equal(report.reservedUsd, 0.1);
    assert.throws(() => f.migration.apply(prepared.runId), /every chunk/);
    const chunk = f.db.prepare("SELECT id FROM bot_migration_chunks WHERE run_id=? AND status='unknown'").get(prepared.runId) as { id: string };
    await f.migration.reconcileChunk(prepared.runId, chunk.id, { knowledge: [], costUsd: 0.04 });
    const resumed = await f.migration.resume(prepared.runId, async (request) => { calls++; return extraction(request); });
    assert.equal(calls, 2);
    assert.equal(resumed.status, "ready");
    assert.equal(resumed.costUsd, 0.05);
  } finally { await f.close(); }
});

test("a changed source cannot silently resume the existing manifest", async () => {
  const f = await fixture();
  try {
    const prepared = await f.migration.prepare({ ...settings, path: f.path });
    await writeFile(f.path, `${JSON.stringify(records[0])}\n`);
    await assert.rejects(f.migration.resume(prepared.runId, async (request) => extraction(request)), /Source changed/);
  } finally { await f.close(); }
});

test("CLI resume rejects a changed extractor before reserving or calling the model", async () => {
  const f = await fixture();
  try {
    const prepared = await f.migration.prepare({ ...settings, path: f.path });
    let calls = 0;
    const extractor: KnowledgeExtractor = async (request) => { calls++; return extraction(request); };
    await assert.rejects(runMigrationCli(["resume", "--run", prepared.runId, "--extractor-version", "other-model-v2"],
      { db: f.db, extractor }), /Extractor version differs/);
    assert.equal(calls, 0);
    assert.equal(f.migration.report(prepared.runId).reservedUsd, 0);
    assert.equal(f.migration.report(prepared.runId).status, "prepared");
    await runMigrationCli(["resume", "--run", prepared.runId, "--extractor-version", settings.extractorVersion], { db: f.db, extractor });
    assert.equal(calls, 2);
  } finally { await f.close(); }
});

test("CLI review shows paginated staged knowledge and actual source quotes without applying it", async () => {
  const f = await fixture();
  try {
    const prepared = await f.migration.prepare({ ...settings, path: f.path });
    await f.migration.resume(prepared.runId, async (request) => extraction(request));
    const first = await runMigrationCli(["review", "--run", prepared.runId, "--limit", "1"], { db: f.db }) as MigrationReview;
    const second = await runMigrationCli(["review", "--run", prepared.runId, "--limit", "1", "--offset", "1"], { db: f.db }) as MigrationReview;
    assert.equal(first.totalCandidates, 2);
    assert.equal(first.nextOffset, 1);
    assert.equal(second.nextOffset, null);
    assert.equal(first.knowledge.length, 1);
    assert.notEqual(first.knowledge[0]!.chunkId, second.knowledge[0]!.chunkId);
    for (const fact of [...first.knowledge, ...second.knowledge]) for (const source of fact.sources) {
      assert.ok(records.find(record => record.id === source.id && record.scope === first.scope)?.text.includes(source.quote!));
    }
    assert.deepEqual(f.memory.recall({ scope: "chat" }), []);
    await assert.rejects(runMigrationCli(["review", "--run", prepared.runId, "--limit", "1001"], { db: f.db }), /--limit/);
  } finally { await f.close(); }
});

test("old forget events and new forget requests prevent migration resurrection", async () => {
  const f = await fixture();
  try {
    await writeFile(f.path, [...records, { kind: "forget", scope: "chat", sourceIds: ["chat:1"] }].map((row) => JSON.stringify(row)).join("\n"));
    const prepared = await f.migration.prepare({ ...settings, path: f.path });
    let seen: string[] = [];
    await f.migration.resume(prepared.runId, async (request) => { seen = request.messages.map((message) => message.id); return extraction(request); });
    assert.deepEqual(seen, ["chat:2"]);
    // A user forgets the remaining fact after extraction but before apply.
    f.memory.forgetSources("chat", ["chat:2"]);
    const report = f.migration.apply(prepared.runId);
    assert.equal(report.blockedCount, 1);
    assert.equal(report.appliedCount, 0);
    assert.equal(f.memory.isSourceForgotten("chat", "chat:1"), true);
    assert.deepEqual(f.memory.recall({ scope: "chat" }), []);
  } finally { await f.close(); }
});

test("unverifiable source quotes are rejected and cannot be applied", async () => {
  const f = await fixture();
  try {
    const prepared = await f.migration.prepare({ ...settings, path: f.path });
    await assert.rejects(f.migration.resume(prepared.runId, async () => ({ costUsd: 0.01,
      knowledge: [{ subject: "user:1", text: "Invented", sources: [{ id: "chat:1", quote: "Never said this" }] }],
    })), /outcome unknown/);
    assert.throws(() => f.migration.apply(prepared.runId), /every chunk/);
  } finally { await f.close(); }
});

test("reservations stop extraction at the cap and CLI dry-run does not write memory", async () => {
  const f = await fixture();
  try {
    const prepared = await f.migration.prepare({ ...settings, path: f.path, maxCostUsd: 0.1 });
    let calls = 0;
    const report = await f.migration.resume(prepared.runId, async (request) => { calls++; return { ...extraction(request), costUsd: 0.1 }; });
    assert.equal(calls, 1);
    assert.equal(report.chunks.pending, 1);
    assert.equal(report.costUsd, 0.1);
    await runMigrationCli(["knowledge", "--source", f.path, "--chat", "chat", "--until", settings.cutoff,
      "--max-cost-usd", "1", "--dry-run"], { db: f.db, extractor: async (request) => extraction(request) });
    assert.deepEqual(f.memory.recall({ scope: "chat" }), []);
  } finally { await f.close(); }
});

test("CLI chunk allowance is configurable without exceeding the total budget", async () => {
  const f = await fixture();
  try {
    const allowances: number[] = [];
    await runMigrationCli(["knowledge", "--source", f.path, "--chat", "chat", "--until", settings.cutoff,
      "--max-cost-usd", "1", "--max-chunk-cost-usd", "0.6", "--chunk-size", "1", "--dry-run"], {
      db: f.db,
      extractor: async (request) => { allowances.push(request.maxCostUsd); return { ...extraction(request), costUsd: request.maxCostUsd }; },
    });
    assert.deepEqual(allowances, [0.6, 0.4]);
    assert.deepEqual(f.memory.recall({ scope: "chat" }), []);
  } finally { await f.close(); }
});

test("normalization uses the highest edit revision and rejects conflicting copies", async () => {
  const f = await fixture();
  try {
    const edited = { ...records[0], text: "I maintain the orchard.", revision: 2 };
    await writeFile(f.path, [records[0], edited].map((row) => JSON.stringify(row)).join("\n"));
    const prepared = await f.migration.prepare({ ...settings, path: f.path });
    await f.migration.resume(prepared.runId, async (request) => {
      assert.equal(request.messages[0]?.text, edited.text);
      return { knowledge: [], costUsd: 0 };
    });
    await writeFile(f.path, [records[0], { ...records[0], text: "Different same-version text" }].map((row) => JSON.stringify(row)).join("\n"));
    await assert.rejects(f.migration.prepare({ ...settings, path: f.path }), /Conflicting copies/);
  } finally { await f.close(); }
});
