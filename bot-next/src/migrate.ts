import { createHash } from "node:crypto";
import { createReadStream } from "node:fs";
import { resolve } from "node:path";
import { createInterface } from "node:readline";
import type { DatabaseSync } from "node:sqlite";
import { atomic, digest, MemoryStore, validateKnowledge, type Knowledge } from "./memory.js";

/** JSONL is an explicit export format, not a dump of provider messages or model reasoning. */
export interface LegacyMessage {
  id: string;
  scope: string;
  authorId: string;
  text: string;
  occurredAt: string;
  revision: number;
}
interface ForgetEvent { kind: "forget"; scope: string; sourceIds: string[] }
type LegacyEvent = LegacyMessage | ForgetEvent;

export interface MigrationManifest {
  version: 1;
  path: string;
  sourceDigest: string;
  scope: string;
  cutoff: string;
  extractorVersion: string;
  policyVersion: string;
  normalizerVersion: "jsonl-v1";
  chunkSize: number;
  maxCostMicros: number;
  maxChunkCostMicros: number;
  sourceCount: number;
  forgottenSourceIds: string[];
}

export interface ExtractRequest {
  runId: string;
  chunkId: string;
  scope: string;
  messages: readonly LegacyMessage[];
  maxCostUsd: number;
}
export interface ExtractResult { knowledge: Knowledge[]; costUsd: number }
export type KnowledgeExtractor = (request: ExtractRequest) => Promise<ExtractResult>;

export interface MigrationReport {
  runId: string;
  status: "prepared" | "extracting" | "ready" | "applied";
  chunks: { pending: number; running: number; done: number; unknown: number };
  costUsd: number;
  reservedUsd: number;
  maxCostUsd: number;
  candidateCount: number;
  appliedCount: number;
  blockedCount: number;
  unresolvedChunks: { id: string; status: "running" | "unknown" }[];
}

export interface MigrationReview {
  runId: string;
  scope: string;
  status: MigrationReport["status"];
  extractorVersion: string;
  totalCandidates: number;
  offset: number;
  nextOffset: number | null;
  knowledge: (Knowledge & { chunkId: string })[];
}

type RunRow = { id: string; manifest_json: string; status: MigrationReport["status"]; applied_count: number; blocked_count: number };
type ChunkRow = {
  id: string; ordinal: number; status: "pending" | "running" | "done" | "unknown";
  allowance_micros: number; cost_micros: number; result_json: string | null;
};

function micros(value: number): number {
  if (!Number.isFinite(value) || value < 0 || value > 100_000) throw new Error("Cost must be finite USD in 0–100000");
  return Math.ceil(value * 1_000_000 - 1e-8);
}
function identifier(value: unknown, field: string): string {
  if ((typeof value !== "string" && typeof value !== "number") || !String(value).trim() || String(value).length > 512) {
    throw new Error(`Invalid ${field}`);
  }
  if (typeof value === "number" && !Number.isSafeInteger(value)) throw new Error(`${field} must preserve its integer identity`);
  return String(value);
}
function timestamp(value: unknown): string {
  const parsed = typeof value === "number" ? value * 1_000 : typeof value === "string" ? Date.parse(value) : NaN;
  if (!Number.isFinite(parsed)) throw new Error("Invalid message timestamp");
  return new Date(parsed).toISOString();
}
function parseEvent(line: string): LegacyEvent {
  const value: unknown = JSON.parse(line);
  if (typeof value !== "object" || value === null || Array.isArray(value)) throw new Error("Expected a JSON object");
  const row = value as Record<string, unknown>;
  const scope = identifier(row.scope ?? row.chatId, "scope");
  if (row.kind === "forget") {
    if (!Array.isArray(row.sourceIds) || row.sourceIds.length > 10_000) throw new Error("Invalid forgotten source IDs");
    return { kind: "forget", scope, sourceIds: row.sourceIds.map((id) => identifier(id, "source ID")) };
  }
  const id = identifier(row.id ?? (row.messageId !== undefined ? `${scope}:${identifier(row.messageId, "message ID")}` : undefined), "message ID");
  if (typeof row.text !== "string" || row.text.length > 32_000) throw new Error("Message text must have at most 32000 characters");
  const revision = row.revision ?? 0;
  if (typeof revision !== "number" || !Number.isSafeInteger(revision) || revision < 0) throw new Error("Invalid revision");
  return { id, scope, authorId: identifier(row.authorId ?? row.fromUserId, "author ID"),
    text: row.text, occurredAt: timestamp(row.occurredAt ?? row.date), revision };
}

async function readSource(path: string): Promise<{ sourceDigest: string; events: LegacyEvent[]; lines: number }> {
  const hash = createHash("sha256");
  let bytes = 0;
  let lines = 0;
  const events: LegacyEvent[] = [];
  const stream = createReadStream(path);
  stream.on("data", (chunk: Buffer | string) => {
    bytes += Buffer.byteLength(chunk);
    if (bytes > 128 * 1024 * 1024) stream.destroy(new Error("JSONL source exceeds 128 MiB; export a bounded scope"));
    hash.update(chunk);
  });
  const reader = createInterface({ input: stream, crlfDelay: Infinity });
  try {
    for await (const line of reader) {
      lines++;
      if (!line.trim()) continue;
      if (events.length >= 100_000) throw new Error("JSONL source exceeds 100000 records; export a bounded scope");
      try { events.push(parseEvent(line)); }
      catch (cause) { throw new Error(`Invalid JSONL record at line ${lines}`, { cause }); }
    }
  } finally { reader.close(); stream.destroy(); }
  return { sourceDigest: hash.digest("hex"), events, lines };
}

function selectMessages(events: LegacyEvent[], scope: string, cutoff: string): { messages: LegacyMessage[]; forgottenSourceIds: string[] } {
  const forgotten = new Set<string>();
  const selected = new Map<string, LegacyMessage>();
  for (const event of events) {
    if (event.scope !== scope) continue;
    if ("kind" in event) { for (const id of event.sourceIds) forgotten.add(id); continue; }
    if (event.occurredAt > cutoff) continue;
    const previous = selected.get(event.id);
    if (!previous || previous.revision < event.revision) selected.set(event.id, event);
    else if (previous.revision === event.revision && digest(previous) !== digest(event)) {
      throw new Error(`Conflicting copies of source ${event.id} at the same revision`);
    }
  }
  return {
    messages: [...selected.values()].filter((event) => !forgotten.has(event.id) && event.text.trim())
      .sort((a, b) => a.occurredAt.localeCompare(b.occurredAt) || a.id.localeCompare(b.id)),
    forgottenSourceIds: [...forgotten].sort(),
  };
}
function chunksOf(messages: LegacyMessage[], size: number): LegacyMessage[][] {
  const chunks: LegacyMessage[][] = [];
  for (let start = 0; start < messages.length; start += size) chunks.push(messages.slice(start, start + size));
  return chunks;
}

export async function inventory(path: string): Promise<{
  path: string; sourceDigest: string; records: number;
  scopes: Record<string, { messages: number; forgetEvents: number; first: string | null; last: string | null }>;
}> {
  const source = await readSource(resolve(path));
  const scopes: Record<string, { messages: number; forgetEvents: number; first: string | null; last: string | null }> = Object.create(null);
  for (const event of source.events) {
    const group = scopes[event.scope] ??= { messages: 0, forgetEvents: 0, first: null, last: null };
    if ("kind" in event) group.forgetEvents++;
    else {
      group.messages++;
      if (group.first === null || event.occurredAt < group.first) group.first = event.occurredAt;
      if (group.last === null || event.occurredAt > group.last) group.last = event.occurredAt;
    }
  }
  return { path: resolve(path), sourceDigest: source.sourceDigest, records: source.events.length, scopes };
}

export class MigrationStore {
  constructor(private readonly db: DatabaseSync, private readonly memory = new MemoryStore(db)) {
    db.exec(`
      CREATE TABLE IF NOT EXISTS bot_migration_runs (
        id TEXT PRIMARY KEY, manifest_json TEXT NOT NULL,
        status TEXT NOT NULL, applied_count INTEGER NOT NULL DEFAULT 0,
        blocked_count INTEGER NOT NULL DEFAULT 0, created_at TEXT NOT NULL
      );
      CREATE TABLE IF NOT EXISTS bot_migration_chunks (
        run_id TEXT NOT NULL, id TEXT NOT NULL, ordinal INTEGER NOT NULL,
        status TEXT NOT NULL, allowance_micros INTEGER NOT NULL DEFAULT 0,
        cost_micros INTEGER NOT NULL DEFAULT 0, result_json TEXT,
        PRIMARY KEY(run_id, id), UNIQUE(run_id, ordinal)
      );
    `);
  }

  async prepare(input: {
    path: string; scope: string; cutoff: string; maxCostUsd: number;
    extractorVersion: string; policyVersion: string; chunkSize?: number; maxChunkCostUsd?: number;
  }): Promise<MigrationReport> {
    const path = resolve(input.path);
    const scope = identifier(input.scope, "scope");
    if (scope.length > 256) throw new Error("scope must have at most 256 characters");
    const cutoff = timestamp(input.cutoff);
    const chunkSize = input.chunkSize ?? 40;
    if (!Number.isInteger(chunkSize) || chunkSize < 1 || chunkSize > 100) throw new Error("chunkSize must be 1–100");
    const source = await readSource(path);
    const selected = selectMessages(source.events, scope, cutoff);
    const manifest: MigrationManifest = {
      version: 1, path, sourceDigest: source.sourceDigest, scope, cutoff,
      extractorVersion: identifier(input.extractorVersion, "extractorVersion"),
      policyVersion: identifier(input.policyVersion, "policyVersion"), normalizerVersion: "jsonl-v1",
      chunkSize, maxCostMicros: micros(input.maxCostUsd),
      maxChunkCostMicros: micros(input.maxChunkCostUsd ?? Math.min(0.25, input.maxCostUsd)),
      sourceCount: selected.messages.length, forgottenSourceIds: selected.forgottenSourceIds,
    };
    if (manifest.maxChunkCostMicros < 1 || manifest.maxCostMicros < 1) throw new Error("Migration requires a positive bounded budget");
    const runId = digest(manifest);
    const chunks = chunksOf(selected.messages, chunkSize);
    atomic(this.db, () => {
      this.db.prepare(`INSERT OR IGNORE INTO bot_migration_runs (id, manifest_json, status, created_at)
        VALUES (?, ?, ?, ?)`).run(runId, JSON.stringify(manifest), chunks.length ? "prepared" : "ready", new Date().toISOString());
      const insert = this.db.prepare(`INSERT OR IGNORE INTO bot_migration_chunks (run_id, id, ordinal, status)
        VALUES (?, ?, ?, 'pending')`);
      chunks.forEach((chunk, ordinal) => insert.run(runId, digest([runId, ordinal, chunk.map((message) => [message.id, message.revision])]), ordinal));
    });
    return this.report(runId);
  }

  private run(id: string): RunRow {
    const row = this.db.prepare("SELECT * FROM bot_migration_runs WHERE id = ?").get(id) as RunRow | undefined;
    if (!row) throw new Error("Migration run not found");
    return row;
  }
  private chunks(id: string): ChunkRow[] {
    return this.db.prepare("SELECT * FROM bot_migration_chunks WHERE run_id = ? ORDER BY ordinal").all(id) as ChunkRow[];
  }

  report(runId: string): MigrationReport {
    const run = this.run(runId);
    const manifest = JSON.parse(run.manifest_json) as MigrationManifest;
    const rows = this.chunks(runId);
    const chunks = { pending: 0, running: 0, done: 0, unknown: 0 };
    let cost = 0; let reserved = 0; let candidates = 0;
    for (const row of rows) {
      chunks[row.status]++;
      cost += row.cost_micros;
      if (row.status === "running" || row.status === "unknown") reserved += row.allowance_micros;
      if (row.result_json) candidates += (JSON.parse(row.result_json) as Knowledge[]).length;
    }
    return { runId, status: run.status, chunks, costUsd: cost / 1e6, reservedUsd: reserved / 1e6,
      maxCostUsd: manifest.maxCostMicros / 1e6, candidateCount: candidates,
      appliedCount: run.applied_count, blockedCount: run.blocked_count,
      unresolvedChunks: rows.flatMap((row) => row.status === "running" || row.status === "unknown"
        ? [{ id: row.id, status: row.status }] : []),
    };
  }

  /** Review staged facts and their exact evidence before the separate apply step. */
  review(runId: string, offset = 0, limit = 100): MigrationReview {
    if (!Number.isSafeInteger(offset) || offset < 0 || !Number.isSafeInteger(limit) || limit < 1 || limit > 1000) {
      throw new Error("Review requires a nonnegative --offset and --limit in 1–1000");
    }
    const run = this.run(runId);
    const manifest = JSON.parse(run.manifest_json) as MigrationManifest;
    const total = this.db.prepare(`SELECT COALESCE(SUM(json_array_length(result_json)),0) AS n
      FROM bot_migration_chunks WHERE run_id=? AND status='done'`).get(runId)!.n as number;
    const rows = this.db.prepare(`SELECT c.id AS chunk_id, fact.value AS knowledge_json
      FROM bot_migration_chunks AS c, json_each(COALESCE(c.result_json,'[]')) AS fact
      WHERE c.run_id=? AND c.status='done' ORDER BY c.ordinal, fact.key LIMIT ? OFFSET ?`)
      .all(runId, limit, offset) as { chunk_id: string; knowledge_json: string }[];
    return { runId, scope: manifest.scope, status: run.status, extractorVersion: manifest.extractorVersion,
      totalCandidates: total, offset, nextOffset: offset + rows.length < total ? offset + rows.length : null,
      knowledge: rows.map((row) => ({ ...JSON.parse(row.knowledge_json) as Knowledge, chunkId: row.chunk_id })),
    };
  }

  private async sourceChunks(manifest: MigrationManifest): Promise<LegacyMessage[][]> {
    const source = await readSource(manifest.path);
    if (source.sourceDigest !== manifest.sourceDigest) throw new Error("Source changed since prepare; create a new migration run");
    return chunksOf(selectMessages(source.events, manifest.scope, manifest.cutoff).messages, manifest.chunkSize);
  }

  /** No target memories are written: this is the dry-run/staging phase, which can spend model budget. */
  async resume(runId: string, extractor: KnowledgeExtractor, expectedExtractorVersion?: string): Promise<MigrationReport> {
    const run = this.run(runId);
    const manifest = JSON.parse(run.manifest_json) as MigrationManifest;
    if (expectedExtractorVersion !== undefined && manifest.extractorVersion !== identifier(expectedExtractorVersion, "extractorVersion")) {
      throw new Error("Extractor version differs from the migration manifest; restore the original provider/model or prepare a new run");
    }
    if (run.status === "applied" || run.status === "ready") return this.report(runId);
    const sourceChunks = await this.sourceChunks(manifest);
    // An unconfirmed call may already have been paid for. Never automatically repeat it.
    if (this.chunks(runId).some((row) => row.status === "running" || row.status === "unknown")) return this.report(runId);
    for (const chunk of this.chunks(runId)) {
      if (chunk.status !== "pending") continue;
      const messages = (sourceChunks[chunk.ordinal] ?? []).filter((message) => !this.memory.isSourceForgotten(manifest.scope, message.id));
      const allowance = atomic(this.db, () => {
        const report = this.report(runId);
        const remaining = manifest.maxCostMicros - micros(report.costUsd) - micros(report.reservedUsd);
        const amount = Math.min(manifest.maxChunkCostMicros, remaining);
        if (amount <= 0) return 0;
        const claim = this.db.prepare(`UPDATE bot_migration_chunks SET status='running', allowance_micros=?
          WHERE run_id=? AND id=? AND status='pending'`).run(amount, runId, chunk.id);
        if (claim.changes !== 1) return 0;
        this.db.prepare("UPDATE bot_migration_runs SET status='extracting' WHERE id=?").run(runId);
        return amount;
      });
      if (allowance <= 0) break;
      try {
        const result = messages.length ? await extractor({ runId, chunkId: chunk.id, scope: manifest.scope,
          messages, maxCostUsd: allowance / 1e6 }) : { knowledge: [], costUsd: 0 };
        this.completeChunk(runId, chunk.id, messages, result);
      } catch (error) {
        if (this.chunks(runId).find((row) => row.id === chunk.id)?.status === "done") throw error;
        this.db.prepare("UPDATE bot_migration_chunks SET status='unknown' WHERE run_id=? AND id=? AND status='running'").run(runId, chunk.id);
        throw new Error(`Extraction outcome unknown for chunk ${chunk.id}; reconcile before resuming`, { cause: error });
      }
    }
    return this.report(runId);
  }

  private completeChunk(runId: string, chunkId: string, messages: readonly LegacyMessage[], result: ExtractResult): void {
    if (!Array.isArray(result.knowledge) || result.knowledge.length > 100) throw new Error("Extractor must return at most 100 facts per chunk");
    const cost = micros(result.costUsd);
    const source = new Map(messages.map((message) => [message.id, message]));
    const knowledge = result.knowledge.map((fact) => {
      validateKnowledge(fact);
      return { subject: fact.subject, text: fact.text, sources: fact.sources.map((citation) => {
        const message = source.get(citation.id);
        if (!message || !citation.quote?.trim() || !message.text.includes(citation.quote)) {
          throw new Error("Every extracted fact needs an exact quote from this chunk");
        }
        return { id: citation.id, quote: citation.quote, occurredAt: message.occurredAt };
      }) };
    });
    atomic(this.db, () => {
      const chunk = this.chunks(runId).find((row) => row.id === chunkId);
      if (!chunk || (chunk.status !== "running" && chunk.status !== "unknown")) throw new Error("Chunk cannot be completed in its current state");
      // Record actual spend even if the adapter violated its cap; stop rather than hiding overspend.
      this.db.prepare(`UPDATE bot_migration_chunks SET status='done', cost_micros=?, result_json=?
        WHERE run_id=? AND id=?`).run(cost, JSON.stringify(knowledge), runId, chunkId);
      if (this.chunks(runId).every((row) => row.status === "done")) {
        this.db.prepare("UPDATE bot_migration_runs SET status='ready' WHERE id=?").run(runId);
      }
    });
    const chunk = this.chunks(runId).find((row) => row.id === chunkId)!;
    if (cost > chunk.allowance_micros) throw new Error("Extractor exceeded its reserved budget; actual cost was recorded");
  }

  /** Supply a verified provider result, or {knowledge:[],costUsd:...} to abandon an uncertain chunk. */
  async reconcileChunk(runId: string, chunkId: string, result: ExtractResult): Promise<MigrationReport> {
    const manifest = JSON.parse(this.run(runId).manifest_json) as MigrationManifest;
    const chunk = this.chunks(runId).find((row) => row.id === chunkId);
    if (!chunk) throw new Error("Chunk not found");
    const source = await this.sourceChunks(manifest);
    this.completeChunk(runId, chunkId, source[chunk.ordinal] ?? [], result);
    return this.report(runId);
  }

  apply(runId: string): MigrationReport {
    return atomic(this.db, () => {
      const run = this.run(runId);
      if (run.status === "applied") return this.report(runId);
      if (run.status !== "ready") throw new Error("Finish or reconcile every chunk before apply");
      const manifest = JSON.parse(run.manifest_json) as MigrationManifest;
      this.memory.forgetSources(manifest.scope, manifest.forgottenSourceIds);
      let applied = 0; let blocked = 0;
      for (const chunk of this.chunks(runId)) {
        for (const knowledge of JSON.parse(chunk.result_json ?? "[]") as Knowledge[]) {
          const result = this.memory.remember({ ...knowledge, scope: manifest.scope, origin: runId });
          if (result.status === "blocked") blocked++; else if (result.status === "created") applied++;
        }
      }
      this.db.prepare(`UPDATE bot_migration_runs SET status='applied', applied_count=?, blocked_count=? WHERE id=?`)
        .run(applied, blocked, runId);
      return this.report(runId);
    });
  }
}

/** Pure CLI entry helper: the host owns configuration, database lifecycle and its paid extractor. */
export async function runMigrationCli(args: string[], options: { db: DatabaseSync; extractor?: KnowledgeExtractor }): Promise<unknown> {
  const command = args[0];
  const flags = new Map<string, string>();
  for (let i = 1; i < args.length; i++) {
    const flag = args[i]!;
    if (flag === "--dry-run") { flags.set(flag, "true"); continue; }
    const value = args[++i];
    if (!flag.startsWith("--") || value === undefined || value.startsWith("--")) throw new Error(`Expected a value for ${flag}`);
    flags.set(flag, value);
  }
  const required = (flag: string): string => { const value = flags.get(flag); if (!value) throw new Error(`Missing ${flag}`); return value; };
  if (command === "inventory") return inventory(required("--source"));
  const migration = new MigrationStore(options.db);
  if (command === "prepare" || command === "knowledge") {
    const report = await migration.prepare({ path: required("--source"), scope: required("--chat"),
      cutoff: required("--until"), maxCostUsd: Number(required("--max-cost-usd")),
      extractorVersion: flags.get("--extractor-version") ?? "host-v1", policyVersion: flags.get("--policy-version") ?? "facts-v1",
      ...(flags.has("--chunk-size") ? { chunkSize: Number(flags.get("--chunk-size")) } : {}),
      ...(flags.has("--max-chunk-cost-usd") ? { maxChunkCostUsd: Number(flags.get("--max-chunk-cost-usd")) } : {}),
    });
    if (command === "knowledge" && options.extractor) return migration.resume(report.runId, options.extractor);
    return report;
  }
  if (command === "resume") {
    if (!options.extractor) throw new Error("A bounded paid extractor is required to resume");
    return migration.resume(required("--run"), options.extractor, flags.get("--extractor-version"));
  }
  if (command === "apply") return migration.apply(required("--run"));
  if (command === "status") return migration.report(required("--run"));
  if (command === "review") return migration.review(required("--run"), Number(flags.get("--offset") ?? 0), Number(flags.get("--limit") ?? 100));
  throw new Error("Expected inventory, prepare, knowledge, resume, status, review or apply");
}
