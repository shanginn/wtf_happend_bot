import assert from "node:assert/strict";
import { mkdtemp, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { CapsuleStageRegistry } from "../src/capsule-stage-registry.js";
import { RunConflictError } from "../src/run-registry.js";

const result = {
  digest: `sha256:${"d".repeat(64)}`,
  entrypoint: ["/data/capsule/run.mjs"],
};

test("replays a staged capsule and rejects idempotency-key reuse", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "capsule-stage-registry-"));
  try {
    const registry = new CapsuleStageRegistry(root);
    let operations = 0;
    const first = await registry.execute("proposal-1:tool", "hash-1", async () => {
      operations += 1;
      return result;
    });
    const replay = await registry.execute("proposal-1:tool", "hash-1", async () => {
      operations += 1;
      return result;
    });

    assert.deepEqual(replay, first);
    assert.equal(operations, 1);
    await assert.rejects(
      registry.execute("proposal-1:tool", "hash-2", async () => result),
      RunConflictError,
    );
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
