import { strict as assert } from "node:assert";
import { DatabaseSync } from "node:sqlite";
import { test } from "node:test";
import { MemoryStore } from "../src/memory.js";

test("memory filters the whole scoped history before applying a result limit", () => {
  const db = new DatabaseSync(":memory:");
  try {
    const memory = new MemoryStore(db);
    memory.remember({ scope: "group", subject: "user:1", text: "Любит чай с чабрецом", sources: [{ id: "group:1" }] });
    for (let i = 0; i < 110; i++) {
      memory.remember({ scope: "group", subject: "user:1", text: `Another fact ${i}`, sources: [{ id: `group:${i + 2}` }] });
    }
    memory.remember({ scope: "private", subject: "user:1", text: "Любит чай с чабрецом", sources: [{ id: "private:1" }] });
    const facts = memory.recall({ scope: "group", query: "ЧАЙ чабрецом", limit: 1 });
    assert.equal(facts.length, 1);
    assert.equal(facts[0]?.scope, "group");
    assert.equal(memory.recall({ scope: "unrelated" }).length, 0);
  } finally { db.close(); }
});

test("forget removes content and blocks textual and source-based resurrection", () => {
  const db = new DatabaseSync(":memory:");
  try {
    const memory = new MemoryStore(db);
    const input = { scope: "group", subject: "user:1", text: "Prefers quiet mornings", sources: [{ id: "group:1", quote: "quiet mornings" }] };
    const saved = memory.remember(input);
    assert.equal(saved.status, "created");
    assert.equal(memory.forget({ scope: "other", id: saved.memory.id }), false);
    assert.equal(memory.forget({ scope: "group", id: saved.memory.id }), true);
    assert.equal(memory.forget({ scope: "group", id: saved.memory.id }), true);
    assert.deepEqual(memory.recall({ scope: "group" }), []);
    assert.equal(memory.remember({ ...input, text: "Prefers silence early in the day" }).status, "blocked");
    assert.equal(memory.remember({ ...input, subject: "renamed", text: " PREFERS   QUIET MORNINGS ", sources: [{ id: "new" }] }).status, "blocked");
    assert.equal(memory.remember({ ...input, scope: "private" }).status, "created");
    const blocks = JSON.stringify(db.prepare("SELECT * FROM bot_memory_blocks").all());
    assert.equal(blocks.includes("quiet"), false);
  } finally { db.close(); }
});

test("duplicate facts merge evidence without duplicating memories", () => {
  const db = new DatabaseSync(":memory:");
  try {
    const memory = new MemoryStore(db);
    const fact = { scope: "chat", subject: "user:1", text: "Maintains the garden" };
    assert.equal(memory.remember({ ...fact, sources: [{ id: "1" }] }).status, "created");
    assert.equal(memory.remember({ ...fact, sources: [{ id: "2" }] }).status, "existing");
    assert.equal(memory.recall({ scope: "chat" }).length, 1);
    assert.equal(memory.recall({ scope: "chat" })[0]?.sources.length, 2);
    memory.forgetSources("chat", ["2"]);
    assert.equal(memory.recall({ scope: "chat" }).length, 0);
    assert.equal(memory.isSourceForgotten("chat", "1"), true);
  } finally { db.close(); }
});

test("invalid or ungrounded memory input is rejected", () => {
  const db = new DatabaseSync(":memory:");
  try {
    const memory = new MemoryStore(db);
    assert.throws(() => memory.remember({ scope: "chat", subject: "user:1", text: "Fact", sources: [] }), /supporting sources/);
    assert.throws(() => memory.recall({ scope: "chat", limit: 1000 }), /limit/);
  } finally { db.close(); }
});
