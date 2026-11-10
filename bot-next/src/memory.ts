import { createHash } from "node:crypto";
import type { DatabaseSync } from "node:sqlite";

export interface MemorySource {
  /** A stable, chat-scoped message identifier; never a username. */
  id: string;
  quote?: string;
  occurredAt?: string;
}

export interface Knowledge {
  subject: string;
  text: string;
  sources: MemorySource[];
}

export interface MemoryRecord extends Knowledge {
  id: string;
  scope: string;
  origin: string | null;
  createdAt: string;
}

export type RememberResult =
  | { status: "created" | "existing"; memory: MemoryRecord }
  | { status: "blocked"; reason: "forgotten" };

export function digest(value: unknown): string {
  return createHash("sha256").update(JSON.stringify(value)).digest("hex");
}

export function normalizedText(value: string): string {
  return value.normalize("NFKC").toLocaleLowerCase("en-US").trim().replace(/\s+/gu, " ");
}

function requiredText(value: unknown, name: string, maximum: number): asserts value is string {
  if (typeof value !== "string" || !value.trim() || value.length > maximum) {
    throw new Error(`${name} must be nonempty text of at most ${maximum} characters`);
  }
}

export function validateKnowledge(value: Knowledge): void {
  requiredText(value.subject, "subject", 256);
  requiredText(value.text, "memory text", 4_000);
  if (!Array.isArray(value.sources) || value.sources.length < 1 || value.sources.length > 32) {
    throw new Error("A memory requires 1–32 supporting sources");
  }
  for (const source of value.sources) {
    requiredText(source.id, "source id", 512);
    if (source.quote !== undefined && (typeof source.quote !== "string" || source.quote.length > 1_000)) {
      throw new Error("A supporting quote must be text of at most 1000 characters");
    }
    if (source.occurredAt !== undefined && !Number.isFinite(Date.parse(source.occurredAt))) {
      throw new Error("A source timestamp must be an ISO date");
    }
  }
}

/** Shared transaction helper. Nested callers use SQLite savepoints. */
export function atomic<T>(db: DatabaseSync, operation: () => T): T {
  // Unique per invocation, including nested remember() during migration apply().
  const name = `wtf_${++savepointSequence}`;
  db.exec(`SAVEPOINT ${name}`);
  try {
    const result = operation();
    db.exec(`RELEASE SAVEPOINT ${name}`);
    return result;
  } catch (error) {
    db.exec(`ROLLBACK TO SAVEPOINT ${name}`);
    db.exec(`RELEASE SAVEPOINT ${name}`);
    throw error;
  }
}
let savepointSequence = 0;

export function initializeMemory(db: DatabaseSync): void {
  db.exec(`
    CREATE TABLE IF NOT EXISTS bot_memories (
      id TEXT PRIMARY KEY,
      scope TEXT NOT NULL,
      subject TEXT NOT NULL,
      text TEXT NOT NULL,
      normalized_text TEXT NOT NULL,
      sources_json TEXT NOT NULL,
      origin TEXT,
      created_at TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS bot_memories_scope ON bot_memories(scope);
    CREATE TABLE IF NOT EXISTS bot_memory_blocks (
      scope TEXT NOT NULL,
      kind TEXT NOT NULL CHECK(kind IN ('text', 'source', 'id')),
      fingerprint TEXT NOT NULL,
      created_at TEXT NOT NULL,
      PRIMARY KEY(scope, kind, fingerprint)
    );
  `);
}

type MemoryRow = {
  id: string; scope: string; subject: string; text: string;
  sources_json: string; origin: string | null; created_at: string;
};

function fromRow(row: MemoryRow): MemoryRecord {
  return {
    id: row.id, scope: row.scope, subject: row.subject, text: row.text,
    sources: JSON.parse(row.sources_json) as MemorySource[],
    origin: row.origin, createdAt: row.created_at,
  };
}

export class MemoryStore {
  constructor(private readonly db: DatabaseSync) { initializeMemory(db); }

  /** This predicate is also used before sending historical messages to an extractor. */
  isSourceForgotten(scope: string, sourceId: string): boolean {
    return this.blocked(scope, "source", digest(sourceId));
  }

  private blocked(scope: string, kind: string, fingerprint: string): boolean {
    return this.db.prepare(
      "SELECT 1 FROM bot_memory_blocks WHERE scope = ? AND kind = ? AND fingerprint = ?",
    ).get(scope, kind, fingerprint) !== undefined;
  }

  remember(input: Knowledge & { scope: string; origin?: string }): RememberResult {
    requiredText(input.scope, "scope", 256);
    validateKnowledge(input);
    const canonical = normalizedText(input.text);
    const id = digest(["memory-v1", input.scope, input.subject, canonical]);
    return atomic(this.db, () => {
      if (this.blocked(input.scope, "text", digest(canonical))
        || input.sources.some((source) => this.isSourceForgotten(input.scope, source.id))) {
        return { status: "blocked", reason: "forgotten" };
      }
      const previous = this.db.prepare("SELECT * FROM bot_memories WHERE id = ?")
        .get(id) as MemoryRow | undefined;
      const sources = new Map<string, MemorySource>();
      for (const source of previous ? fromRow(previous).sources : []) sources.set(source.id, source);
      for (const source of input.sources) sources.set(source.id, source);
      // Keep provenance bounded without removing the already retained evidence.
      const merged = [...sources.values()].slice(0, 32);
      if (previous) {
        this.db.prepare("UPDATE bot_memories SET sources_json = ? WHERE id = ?")
          .run(JSON.stringify(merged), id);
        return { status: "existing", memory: { ...fromRow(previous), sources: merged } };
      }
      const memory: MemoryRecord = {
        id, scope: input.scope, subject: input.subject, text: input.text.trim(), sources: merged,
        origin: input.origin ?? null, createdAt: new Date().toISOString(),
      };
      this.db.prepare(`INSERT INTO bot_memories
        (id, scope, subject, text, normalized_text, sources_json, origin, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)`)
        .run(id, input.scope, input.subject, memory.text, canonical, JSON.stringify(merged),
          memory.origin, memory.createdAt);
      return { status: "created", memory };
    });
  }

  recall(input: { scope: string; query?: string; subject?: string; limit?: number }): MemoryRecord[] {
    requiredText(input.scope, "scope", 256);
    const limit = input.limit ?? 10;
    if (!Number.isInteger(limit) || limit < 1 || limit > 100) throw new Error("limit must be 1–100");
    const tokens = normalizedText(input.query ?? "").split(" ").filter(Boolean).slice(0, 20);
    const conditions = ["scope = ?"];
    const parameters: (string | number)[] = [input.scope];
    if (input.subject !== undefined) { conditions.push("subject = ?"); parameters.push(input.subject); }
    for (const token of tokens) { conditions.push("instr(normalized_text, ?) > 0"); parameters.push(token); }
    parameters.push(limit);
    // Filter the full scoped set before LIMIT: older relevant facts remain searchable.
    return (this.db.prepare(`SELECT * FROM bot_memories WHERE ${conditions.join(" AND ")}
      ORDER BY created_at DESC, id ASC LIMIT ?`).all(...parameters) as MemoryRow[]).map(fromRow);
  }

  forget(input: { scope: string; id: string }): boolean {
    return atomic(this.db, () => {
      const row = this.db.prepare("SELECT * FROM bot_memories WHERE scope = ? AND id = ?")
        .get(input.scope, input.id) as MemoryRow | undefined;
      if (!row) return this.blocked(input.scope, "id", input.id);
      const block = this.db.prepare(`INSERT OR IGNORE INTO bot_memory_blocks
        (scope, kind, fingerprint, created_at) VALUES (?, ?, ?, ?)`);
      const now = new Date().toISOString();
      block.run(input.scope, "id", row.id, now);
      block.run(input.scope, "text", digest(normalizedText(row.text)), now);
      for (const source of fromRow(row).sources) block.run(input.scope, "source", digest(source.id), now);
      // Remove content and quotes; tombstones retain only hashes. Backups need their own retention policy.
      this.db.prepare("DELETE FROM bot_memories WHERE scope = ? AND id = ?").run(input.scope, input.id);
      return true;
    });
  }

  /** Import deletion evidence before historical knowledge; conservative at source granularity. */
  forgetSources(scope: string, sourceIds: string[]): void {
    requiredText(scope, "scope", 256);
    atomic(this.db, () => {
      const block = this.db.prepare(`INSERT OR IGNORE INTO bot_memory_blocks
        (scope, kind, fingerprint, created_at) VALUES (?, 'source', ?, ?)`);
      const now = new Date().toISOString();
      for (const id of sourceIds) { requiredText(id, "source id", 512); block.run(scope, digest(id), now); }
      const ids = new Set(sourceIds);
      const rows = this.db.prepare("SELECT * FROM bot_memories WHERE scope = ?").all(scope) as MemoryRow[];
      for (const row of rows) {
        if (fromRow(row).sources.some((source) => ids.has(source.id))) this.forget({ scope, id: row.id });
      }
    });
  }
}
