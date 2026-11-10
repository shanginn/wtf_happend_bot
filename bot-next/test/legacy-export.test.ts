import { strict as assert } from "node:assert";
import { test } from "node:test";
import { mapForgottenSources, mergeLegacyMessages, normalizeLegacyMessage, normalizeLegacyUpdate, selectLegacySpaceIds, type LegacyMemoryVersion } from "../src/legacy-export.js";

const scope = "-100123";

test("legacy bindings preserve topic tombstones while isolating chat and bot identity", () => {
  const root = { space_id: "root", bot_instance_id: "custom", platform: "telegram", external_conversation_id: scope, external_thread_id: "" };
  const bindings = [root, { ...root, space_id: "old-topic", external_thread_id: "17" },
    { ...root, space_id: "other-chat", external_conversation_id: "-999" },
    { ...root, space_id: "other-platform", platform: "slack" },
    { ...root, space_id: "other-bot", bot_instance_id: "another" }];
  assert.deepEqual(selectLegacySpaceIds(bindings, scope, "custom"), ["old-topic", "root"]);
  assert.throws(() => selectLegacySpaceIds(bindings, scope), /--legacy-bot-instance/);
  assert.deepEqual(selectLegacySpaceIds(bindings, "-888"), []);
});

function row(updateId: number, message: Record<string, unknown>) {
  return { update_id: String(updateId), chat_id: scope,
    update: JSON.stringify({ update_id: updateId, message: { message_id: 42, chat: { id: Number(scope) },
      from: { id: 7, username: "mutable" }, date: 1_700_000_000, text: "I grow tomatoes", ...message } }) };
}

test("legacy normalization keeps stable identities and original dates while excluding payload metadata", () => {
  const message = normalizeLegacyUpdate(row(100, { caption: "unused", photo: [{ file_id: "private-file" }],
    contact: { phone_number: "sensitive-number" }, reply_to_message: { text: "unrelated quoted history" } }), scope);
  assert.deepEqual(message, { id: `${scope}:42`, scope, authorId: "telegram_user:7", text: "I grow tomatoes",
    occurredAt: "2023-11-14T22:13:20.000Z", revision: 101 });
  const exported = JSON.stringify(message);
  assert.equal(exported.includes("private-file"), false);
  assert.equal(exported.includes("sensitive-number"), false);
  assert.equal(exported.includes("unrelated"), false);
});

test("channel sender identity is not invented from a signature or placeholder user", () => {
  const message = normalizeLegacyUpdate(row(100, { sender_chat: { id: -777 }, author_signature: "John" }), scope);
  assert.equal(message?.authorId, "telegram_chat:-777");
  assert.throws(() => normalizeLegacyUpdate(row(100, { chat: { id: -999 } }), scope), /different chat/);
  assert.equal(normalizeLegacyUpdate({ update_id: "1", chat_id: scope, update: JSON.stringify({ callback_query: {} }) }, scope), null);
});

test("updates override duplicate old messages and latest edit wins without changing source identity", () => {
  const old = normalizeLegacyMessage({ id: 1, message_id: 42, chat_id: scope, from_user_id: 7,
    text: "Old table content", date: 1_700_000_000 }, scope)!;
  const initial = normalizeLegacyUpdate(row(100, {}), scope)!;
  const edit = normalizeLegacyUpdate(row(101, { text: "I grow peppers", edit_date: 1_700_000_001 }), scope)!;
  assert.deepEqual(mergeLegacyMessages([initial, edit, old]), [edit]);
  assert.equal(edit.id, initial.id);
  assert.equal(edit.occurredAt, initial.occurredAt);
  assert.equal(normalizeLegacyUpdate(row(101, { edit_date: 1_700_000_001 }), scope, 1_700_000_000), null);
  const cleared = normalizeLegacyUpdate(row(102, { text: "", caption: "", edit_date: 1_700_000_002 }), scope)!;
  assert.equal(cleared.text, "");
  assert.deepEqual(mergeLegacyMessages([initial, edit, old, cleared]), [cleared]);
});

test("forget mapping follows the original memory evidence, not just the deletion request", () => {
  const source = normalizeLegacyUpdate(row(100, {}), scope)!;
  const base: LegacyMemoryVersion = { id: "m1", space_id: "space", status: "superseded", supersedes_memory_id: null,
    participant_key: "telegram_user:7", quote: "I grow tomatoes",
    provenance_json: JSON.stringify({ authority: { evidence: [{ updateId: 100 }] } }) };
  const forgotten = { ...base, id: "m2", status: "forgotten", supersedes_memory_id: "m1", quote: "",
    provenance_json: JSON.stringify({ authority: { evidence: [{ updateId: 200 }] } }) };
  assert.deepEqual(mapForgottenSources([base, forgotten], [source], new Map([["100", source.id]])), {
    sourceIds: [source.id], forgottenMemories: 1,
  });
  assert.deepEqual(mapForgottenSources([{ ...base, status: "forgotten", provenance_json: '{"source":"legacy-import"}' }], [source], new Map()).sourceIds, [source.id]);
});

test("invalid or unmappable deletion chains stop export instead of reviving forgotten facts", () => {
  const source = normalizeLegacyUpdate(row(100, {}), scope)!;
  const forgotten: LegacyMemoryVersion = { id: "m1", space_id: "one", status: "forgotten", supersedes_memory_id: null,
    participant_key: "telegram_user:7", quote: "unmatched quote", provenance_json: "{}" };
  assert.throws(() => mapForgottenSources([forgotten], [source], new Map()), /could not be mapped/);
  assert.throws(() => mapForgottenSources([{ ...forgotten, provenance_json: JSON.stringify({ authority: { evidence: [{ updateId: 200 }] } }) }],
    [source], new Map([["200", source.id]])), /could not be mapped/);
  assert.throws(() => mapForgottenSources([{ ...forgotten, supersedes_memory_id: "missing" }], [source], new Map()), /Incomplete/);
  assert.throws(() => mapForgottenSources([{ ...forgotten, supersedes_memory_id: "m1" }], [source], new Map()), /Invalid/);
  assert.throws(() => mapForgottenSources([{ ...forgotten, supersedes_memory_id: "m2" },
    { ...forgotten, id: "m2", space_id: "other", status: "superseded" }], [source], new Map()), /Invalid/);
});
