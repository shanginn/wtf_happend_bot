import assert from "node:assert/strict";
import { mkdtemp, rm } from "node:fs/promises";
import os from "node:os";
import path from "node:path";
import test from "node:test";

import { API_VERSION, type SandboxExecutionResult } from "../src/contracts.js";
import { RunConflictError, RunRegistry } from "../src/run-registry.js";

function result(runId: string): SandboxExecutionResult {
  return {
    apiVersion: API_VERSION,
    runId,
    status: "completed",
    exitCode: 0,
    signal: null,
    stdout: { text: "ok", bytesSeen: 2, bytesCaptured: 2, truncated: false },
    stderr: { text: "", bytesSeen: 0, bytesCaptured: 0, truncated: false },
    artifacts: [],
    error: null,
    audit: {
      requestSha256: "a".repeat(64),
      capsuleSha256: "b".repeat(64),
      releaseDigest: `sha256:${"c".repeat(64)}`,
      imageBuildId: "00000000-0000-4000-8000-000000000000",
      gondolinCommit: "10b510625dde73cbfd15ac2fc1ae7b8ef642c62c",
      vmId: "vm-1",
      startedAt: "2026-08-12T00:00:00.000Z",
      finishedAt: "2026-08-12T00:00:01.000Z",
      durationMs: 1000,
    },
  };
}

test("replays terminal results and rejects run id reuse", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "sandbox-registry-"));
  try {
    const registry = new RunRegistry(root);
    let executions = 0;
    const first = await registry.execute("run-1", "hash-1", async () => {
      executions += 1;
      return result("run-1");
    });
    const replay = await registry.execute("run-1", "hash-1", async () => {
      executions += 1;
      return result("run-1");
    });

    assert.deepEqual(replay, first);
    assert.equal(executions, 1);
    await assert.rejects(
      registry.execute("run-1", "hash-2", async () => result("run-1")),
      RunConflictError,
    );
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});

test("coalesces concurrent calls for the same run", async () => {
  const root = await mkdtemp(path.join(os.tmpdir(), "sandbox-registry-"));
  try {
    const registry = new RunRegistry(root);
    let executions = 0;
    let release!: () => void;
    const gate = new Promise<void>((resolve) => {
      release = resolve;
    });
    const operation = async () => {
      executions += 1;
      await gate;
      return result("run-2");
    };

    const first = registry.execute("run-2", "hash-2", operation);
    const second = registry.execute("run-2", "hash-2", operation);
    release();
    const [firstResult, secondResult] = await Promise.all([first, second]);

    assert.deepEqual(secondResult, firstResult);
    assert.equal(executions, 1);
  } finally {
    await rm(root, { recursive: true, force: true });
  }
});
