import { open, unlink } from "node:fs/promises";
import { resolve } from "node:path";
import { Client, type QueryResultRow } from "pg";
import type { LegacyMessage } from "./migrate.js";

type Row = Record<string, unknown>;
function object(value: unknown): Row | null {
  return value !== null && typeof value === "object" && !Array.isArray(value) ? value as Row : null;
}
function numericId(value: unknown): string | null {
  if (typeof value === "number" && !Number.isSafeInteger(value)) return null;
  const text = String(value ?? "");
  return /^-?\d+$/u.test(text) ? text : null;
}
function seconds(value: unknown): number | null {
  const text = numericId(value);
  if (text === null) return null;
  const number = Number(text);
  return Number.isSafeInteger(number) && number > 0 && number < 253_402_300_800 ? number : null;
}
function parsedPayload(value: unknown): Row | null {
  if (typeof value !== "string") return object(value);
  return object(JSON.parse(value));
}

/** Only original message text/caption and trusted identity fields leave the legacy payload. */
export function normalizeLegacyUpdate(row: Row, scope: string, cutoffSeconds = Infinity): LegacyMessage | null {
  if (numericId(row.chat_id) !== scope) throw new Error("Update row escaped the requested chat");
  const payload = parsedPayload(row.update);
  if (!payload) throw new Error("Invalid stored Telegram update");
  const names = ["message", "edited_message", "channel_post", "edited_channel_post", "business_message", "edited_business_message", "guest_message"];
  const message = names.map((name) => object(payload[name])).find((value) => value !== null);
  if (!message) return null; // callbacks, payments, reactions and lifecycle metadata are not facts.
  const messageChatId = numericId(object(message.chat)?.id);
  if (messageChatId !== scope) throw new Error("Stored Telegram payload has a different chat identity");
  const messageId = numericId(message.message_id);
  const date = seconds(message.date);
  const edited = seconds(message.edit_date);
  if (!messageId || !date) return null;
  if (date > cutoffSeconds || (edited !== null && edited > cutoffSeconds)) return null;
  const text = typeof message.text === "string" ? message.text : typeof message.caption === "string" ? message.caption : "";
  // A cleared caption is still an authoritative newer revision; omitting it would revive the old caption.
  const isEdit = edited !== null || ["edited_message", "edited_channel_post", "edited_business_message"].some((name) => object(payload[name]) !== null);
  if (!text.trim() && !isEdit) return null;
  if (text.length > 32_000) throw new Error("Legacy text exceeds the normalized message bound");
  const senderChat = numericId(object(message.sender_chat)?.id);
  const senderUser = numericId(object(message.from)?.id);
  const authorId = senderChat !== null ? `telegram_chat:${senderChat}`
    : senderUser !== null ? `telegram_user:${senderUser}` : `telegram_chat:${scope}`;
  const updateId = Number(numericId(row.update_id));
  if (!Number.isSafeInteger(updateId) || updateId < 0) throw new Error("Invalid update identity");
  return { id: `${scope}:${messageId}`, scope, authorId, text,
    occurredAt: new Date(date * 1_000).toISOString(), revision: updateId + 1 };
}

/** Field names come from the 2025 messages migrations; media identifiers are intentionally omitted. */
export function normalizeLegacyMessage(row: Row, scope: string, cutoffSeconds = Infinity): LegacyMessage | null {
  if (numericId(row.chat_id) !== scope) throw new Error("Message row escaped the requested chat");
  const messageId = numericId(row.message_id);
  const author = numericId(row.from_user_id);
  const date = seconds(row.date);
  if (!messageId || !author || !date || date > cutoffSeconds || typeof row.text !== "string" || !row.text.trim()) return null;
  if (row.text.length > 32_000) throw new Error("Legacy text exceeds the normalized message bound");
  return { id: `${scope}:${messageId}`, scope, authorId: `telegram_user:${author}`, text: row.text,
    occurredAt: new Date(date * 1000).toISOString(), revision: 0 };
}

export function mergeLegacyMessages(messages: readonly LegacyMessage[]): LegacyMessage[] {
  const selected = new Map<string, LegacyMessage>();
  for (const message of messages) {
    const previous = selected.get(message.id);
    if (!previous || previous.revision < message.revision) selected.set(message.id, message);
    // The normalized update always wins over messages fallback (revision 0).
    else if (previous.revision === message.revision && previous.text !== message.text) {
      throw new Error("Conflicting legacy messages at the same revision");
    }
  }
  return [...selected.values()].sort((a, b) => a.occurredAt.localeCompare(b.occurredAt) || a.id.localeCompare(b.id));
}

export interface LegacyMemoryVersion {
  id: string;
  space_id: string;
  status: string;
  supersedes_memory_id: string | null;
  participant_key: string;
  quote: string;
  provenance_json: string;
}

/** Follow only host-selected same-chat Space chains. Ambiguous old deletion evidence fails closed. */
export function mapForgottenSources(
  memories: readonly LegacyMemoryVersion[], messages: readonly LegacyMessage[], updateSources: ReadonlyMap<string, string>,
): { sourceIds: string[]; forgottenMemories: number } {
  const byId = new Map(memories.map((memory) => [memory.id, memory]));
  const sourceIds = new Set<string>();
  const forgotten = memories.filter((memory) => memory.status === "forgotten");
  for (const target of forgotten) {
    const visited = new Set<string>();
    const matched = new Set<string>();
    let memory: LegacyMemoryVersion | undefined = target;
    while (memory) {
      if (visited.has(memory.id) || memory.space_id !== target.space_id) throw new Error("Invalid legacy memory revision chain");
      visited.add(memory.id);
      const provenance = parsedPayload(memory.provenance_json);
      if (!provenance) throw new Error("Invalid legacy memory provenance");
      const evidence = object(provenance.authority)?.evidence;
      const updateIds: unknown[] = Array.isArray(provenance.evidenceUpdateIds) ? [...provenance.evidenceUpdateIds] : [];
      if (Array.isArray(evidence)) for (const item of evidence) updateIds.push(object(item)?.updateId);
      // A forget revision's evidence proves the deletion request, not the original fact.
      if (memory.status !== "forgotten") {
        for (const updateId of updateIds) {
          const id = numericId(updateId);
          const sourceId = id === null ? undefined : updateSources.get(id);
          if (sourceId) matched.add(sourceId);
        }
      }
      // Legacy-import memories have quote/context but no structured update references.
      if (memory.quote.trim()) {
        for (const message of messages) {
          if (message.authorId === memory.participant_key && message.text.includes(memory.quote)) matched.add(message.id);
        }
      }
      const parentId: string | null = memory.supersedes_memory_id;
      if (parentId && !byId.has(parentId)) throw new Error("Incomplete legacy memory revision chain");
      memory = parentId ? byId.get(parentId) : undefined;
    }
    if (matched.size === 0 && messages.length > 0) {
      throw new Error("A forgotten legacy memory could not be mapped to source messages; provide explicit deletion mappings before exporting");
    }
    for (const id of matched) sourceIds.add(id);
  }
  return { sourceIds: [...sourceIds].sort(), forgottenMemories: forgotten.length };
}

interface LegacyConnectionOptions { connectionString?: string; scope: string; botInstanceId?: string }

export interface LegacySpaceBinding {
  space_id: string;
  bot_instance_id: string;
  platform: string;
  external_conversation_id: string;
  external_thread_id: string;
}

/** Pre-collapse topic spaces belong to the same chat; never silently choose another bot instance. */
export function selectLegacySpaceIds(bindings: readonly LegacySpaceBinding[], scope: string, botInstanceId = "default"): string[] {
  if (!botInstanceId.trim() || botInstanceId.length > 256) throw new Error("Invalid legacy bot instance identity");
  const chatBindings = bindings.filter((binding) => binding.platform === "telegram" && binding.external_conversation_id === scope);
  const selected = chatBindings.filter((binding) => binding.bot_instance_id === botInstanceId);
  if (chatBindings.length && !selected.length) {
    throw new Error("Legacy chat bindings use another bot instance; select its BOT_INSTANCE_ID with --legacy-bot-instance");
  }
  return [...new Set(selected.map((binding) => binding.space_id))].sort();
}

async function withLegacy<T>(options: LegacyConnectionOptions, use: (client: Client, tables: Map<string, Set<string>>) => Promise<T>): Promise<T> {
  if (numericId(options.scope) !== options.scope) throw new Error("Legacy scope must be a Telegram chat ID");
  const connectionString = options.connectionString ?? process.env.LEGACY_DATABASE_URL;
  if (!connectionString) throw new Error("Set LEGACY_DATABASE_URL for the read-only legacy database connection");
  const client = new Client({ connectionString, connectionTimeoutMillis: 10_000,
    application_name: "wtf-knowledge-export-readonly", statement_timeout: 30_000 });
  try {
    await client.connect();
    await client.query("BEGIN TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY");
    const columns = await client.query<{ table_name: string; column_name: string }>(`SELECT table_name, column_name
      FROM information_schema.columns WHERE table_schema='public'
      AND table_name IN ('messages','update_records','space_bindings','space_memory_versions')`);
    const tables = new Map<string, Set<string>>();
    for (const column of columns.rows) {
      const set = tables.get(column.table_name) ?? new Set<string>();
      set.add(column.column_name); tables.set(column.table_name, set);
    }
    return await use(client, tables);
  } finally {
    // No migration, writes or transaction commits are issued to the source database.
    await client.query("ROLLBACK").catch(() => undefined);
    await client.end().catch(() => undefined);
  }
}
function requireColumns(tables: Map<string, Set<string>>, table: string, columns: string[]): boolean {
  const actual = tables.get(table);
  if (!actual) return false;
  if (columns.some((column) => !actual.has(column))) throw new Error(`Unsupported legacy schema in ${table}; required columns are missing`);
  return true;
}

export async function inventoryLegacyPostgres(options: LegacyConnectionOptions): Promise<{
  scope: string; tables: { name: string; rows: string }[]; tombstonesAvailable: boolean;
}> {
  return withLegacy(options, async (client, tables) => {
    const result: { name: string; rows: string }[] = [];
    for (const table of ["update_records", "messages"] as const) {
      if (requireColumns(tables, table, ["chat_id"])) {
        const count = await client.query<{ count: string }>(`SELECT COUNT(*)::text AS count FROM public.${table} WHERE chat_id=$1`, [options.scope]);
        result.push({ name: table, rows: count.rows[0]!.count });
      }
    }
    if (!result.length) throw new Error("No supported legacy message tables found");
    return { scope: options.scope, tables: result, tombstonesAvailable: tables.has("space_memory_versions") && tables.has("space_bindings") };
  });
}

export async function exportLegacyPostgres(options: LegacyConnectionOptions & {
  path: string; cutoff?: string; maxMessages?: number; maxSourceRows?: number;
}): Promise<{ path: string; scope: string; messages: number; forgottenSources: number; sourceRows: number; cutoff: string }> {
  const cutoff = options.cutoff ?? new Date().toISOString();
  const cutoffSeconds = Date.parse(cutoff) / 1000;
  if (!Number.isFinite(cutoffSeconds)) throw new Error("Invalid export cutoff");
  const maxMessages = options.maxMessages ?? 100_000;
  const maxSourceRows = options.maxSourceRows ?? 200_000;
  if (!Number.isSafeInteger(maxMessages) || maxMessages < 1 || maxMessages > 100_000
    || !Number.isSafeInteger(maxSourceRows) || maxSourceRows < 1 || maxSourceRows > 1_000_000) throw new Error("Invalid bounded export limits");
  return withLegacy(options, async (client, tables) => {
    const messages: LegacyMessage[] = [];
    const updateSources = new Map<string, string>();
    let sourceRows = 0;
    let normalizedBytes = 0;
    const retain = (message: LegacyMessage): void => {
      normalizedBytes += Buffer.byteLength(JSON.stringify(message));
      if (normalizedBytes > 128 * 1024 * 1024) throw new Error("Legacy normalized working set exceeds 128 MiB");
      messages.push(message);
    };
    async function rows<T extends QueryResultRow>(table: "update_records" | "messages", columns: string, key: string, accept: (row: T) => void): Promise<void> {
      let after = "-1";
      for (;;) {
        const page = await client.query<T>(`SELECT ${columns} FROM public.${table}
          WHERE chat_id=$1 AND ${key}>$2 ORDER BY ${key} LIMIT 1000`, [options.scope, after]);
        if (!page.rows.length) break;
        sourceRows += page.rows.length;
        if (sourceRows > maxSourceRows) throw new Error("Legacy export reached the source-row bound; narrow the source snapshot");
        for (const row of page.rows) accept(row);
        after = String(page.rows.at(-1)![key]);
      }
    }
    const hasUpdates = requireColumns(tables, "update_records", ["update_id", "update", "chat_id"]);
    const hasMessages = requireColumns(tables, "messages", ["id", "message_id", "chat_id", "from_user_id", "text", "date"]);
    if (!hasUpdates && !hasMessages) throw new Error("No supported legacy message source found");
    if (hasUpdates) await rows<Row>("update_records", 'update_id, "update", chat_id', "update_id", (row) => {
      const message = normalizeLegacyUpdate(row, options.scope, cutoffSeconds);
      if (message) { retain(message); updateSources.set(String(row.update_id), message.id); }
    });
    if (hasMessages) await rows<Row>("messages", "id, message_id, chat_id, from_user_id, text, date", "id", (row) => {
      const message = normalizeLegacyMessage(row, options.scope, cutoffSeconds);
      if (message) retain(message);
    });
    const selected = mergeLegacyMessages(messages);
    if (selected.length > maxMessages) throw new Error("Legacy export exceeds the normalized-message bound");
    let forgottenSources: string[] = [];
    const hasMemory = requireColumns(tables, "space_memory_versions", ["id", "space_id", "status", "supersedes_memory_id", "participant_key", "quote", "provenance_json"]);
    const hasBindings = requireColumns(tables, "space_bindings", ["space_id", "platform", "bot_instance_id", "external_conversation_id", "external_thread_id"]);
    if (hasMemory !== hasBindings) throw new Error("Incomplete Space schema; cannot establish forgotten-memory boundaries");
    if (hasMemory && hasBindings) {
      const bindings = await client.query<LegacySpaceBinding>(`SELECT space_id, bot_instance_id, platform,
        external_conversation_id, external_thread_id FROM public.space_bindings
        WHERE platform='telegram' AND external_conversation_id=$1 LIMIT 1001`, [options.scope]);
      if (bindings.rows.length > 1000) throw new Error("Legacy chat bindings exceed the export bound");
      const spaceIds = selectLegacySpaceIds(bindings.rows, options.scope, options.botInstanceId);
      const versions = await client.query<LegacyMemoryVersion>(`SELECT m.id, m.space_id, m.status, m.supersedes_memory_id,
        m.participant_key, m.quote, m.provenance_json FROM public.space_memory_versions m
        WHERE m.space_id=ANY($1::text[])
        ORDER BY m.space_id, m.id LIMIT 100001`, [spaceIds]);
      if (versions.rows.length > 100_000) throw new Error("Legacy memory revisions exceed the export bound");
      forgottenSources = mapForgottenSources(versions.rows, selected, updateSources).sourceIds;
    }
    const forgottenSet = new Set(forgottenSources);
    const retained = selected.filter((message) => message.text.trim() && !forgottenSet.has(message.id));
    const path = resolve(options.path);
    // A new owner-only file: no overwrites, no accidental replacement of a live archive.
    const file = await open(path, "wx", 0o600);
    try {
      let bytes = 0;
      const lines: unknown[] = [...retained];
      for (let start = 0; start < forgottenSources.length; start += 10_000) {
        lines.push({ kind: "forget", scope: options.scope, sourceIds: forgottenSources.slice(start, start + 10_000) });
      }
      if (lines.length > 100_000) throw new Error("Normalized export plus deletion records exceeds 100000 records");
      for (const value of lines) {
        const line = `${JSON.stringify(value)}\n`;
        bytes += Buffer.byteLength(line);
        if (bytes > 128 * 1024 * 1024) throw new Error("Normalized export exceeds 128 MiB");
        await file.writeFile(line);
      }
      await file.sync();
    } catch (error) {
      await file.close();
      await unlink(path);
      throw error;
    }
    await file.close();
    return { path, scope: options.scope, messages: retained.length, forgottenSources: forgottenSources.length,
      sourceRows, cutoff: new Date(cutoffSeconds * 1000).toISOString() };
  });
}
