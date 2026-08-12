import assert from "node:assert/strict";
import test from "node:test";

import {
  ContractError,
  parseCapsuleStageRequest,
  parseExecutionRequest,
  type BrokerLimits,
} from "../src/contracts.js";

const brokerLimits: BrokerLimits = {
  maxTimeoutMs: 120_000,
  maxStdoutBytes: 1_048_576,
  maxStderrBytes: 1_048_576,
  maxOutputBytes: 16_777_216,
  maxOutputFiles: 128,
  maxMemoryMiB: 1024,
  maxCpus: 4,
};
const imageBuildId = "00000000-0000-4000-8000-000000000000";

function request(): Record<string, unknown> {
  return {
    apiVersion: "sandbox.wtf/v1",
    runId: "run-001",
    spaceId: "space-001",
    release: {
      id: "release-001",
      digest: `sha256:${"a".repeat(64)}`,
    },
    capsule: {
      digest: `sha256:${"b".repeat(64)}`,
      entrypoint: ["/data/capsule/run", "--json"],
    },
    runtime: { imageBuildId },
    input: { value: 42 },
    limits: {
      timeoutMs: 30_000,
      maxStdoutBytes: 262_144,
      maxStderrBytes: 262_144,
      maxOutputBytes: 4_194_304,
      maxOutputFiles: 32,
      memoryMiB: 256,
      cpus: 1,
    },
    network: { mode: "deny" },
  };
}

test("accepts the strict deny-network contract", () => {
  const parsed = parseExecutionRequest(request(), brokerLimits, imageBuildId);
  assert.equal(parsed.network.mode, "deny");
  assert.equal(parsed.runtime.imageBuildId, imageBuildId);
  assert.deepEqual(parsed.input, { value: 42 });
});

test("rejects a mutable or broker-mismatched runtime image", () => {
  const mutableRuntime = request();
  mutableRuntime.runtime = { imageBuildId: "latest" };
  assert.throws(
    () => parseExecutionRequest(mutableRuntime, brokerLimits, imageBuildId),
    /runtime.imageBuildId must be an immutable Gondolin build UUID/,
  );

  const otherRuntime = request();
  otherRuntime.runtime = {
    imageBuildId: "11111111-1111-4111-8111-111111111111",
  };
  assert.throws(
    () => parseExecutionRequest(otherRuntime, brokerLimits, imageBuildId),
    /does not match the broker's immutable Gondolin image/,
  );

  const unsupportedUuidVersion = request();
  unsupportedUuidVersion.runtime = {
    imageBuildId: "11111111-1111-6111-8111-111111111111",
  };
  assert.throws(
    () =>
      parseExecutionRequest(
        unsupportedUuidVersion,
        brokerLimits,
        imageBuildId,
      ),
    /immutable Gondolin build UUID/,
  );
});

test("accepts bounded JavaScript staging and rejects authority fields", () => {
  const staged = parseCapsuleStageRequest({
    apiVersion: "sandbox.wtf/v1",
    proposalId: "proposal-1",
    spaceId: "space-1",
    name: "daily-summary",
    language: "javascript",
    source: "console.log('ok');\n",
    entrypoint: "tools/run.mjs",
  });
  assert.equal(staged.entrypoint, "tools/run.mjs");

  assert.throws(
    () =>
      parseCapsuleStageRequest({
        ...staged,
        network: { mode: "allow" },
      }),
    /unknown field network/,
  );
  assert.throws(
    () => parseCapsuleStageRequest({ ...staged, source: "x".repeat(65_537) }),
    /65536/,
  );
});

test("rejects network access and secret extension fields", () => {
  const withNetwork = request();
  withNetwork.network = { mode: "allow" };
  assert.throws(
    () => parseExecutionRequest(withNetwork, brokerLimits, imageBuildId),
    ContractError,
  );

  const withSecrets = request();
  withSecrets.secrets = { apiToken: "do-not-send" };
  assert.throws(
    () => parseExecutionRequest(withSecrets, brokerLimits, imageBuildId),
    /unknown field secrets/,
  );
});

test("rejects mutable or oversized execution parameters", () => {
  const mutableCapsule = request();
  mutableCapsule.capsule = {
    digest: "latest",
    entrypoint: ["/data/capsule/run"],
  };
  assert.throws(
    () => parseExecutionRequest(mutableCapsule, brokerLimits, imageBuildId),
    /capsule.digest/,
  );

  const excessive = request();
  excessive.limits = {
    ...(excessive.limits as Record<string, unknown>),
    memoryMiB: 2048,
  };
  assert.throws(
    () => parseExecutionRequest(excessive, brokerLimits, imageBuildId),
    /limits.memoryMiB/,
  );
});

test("rejects an entrypoint that escapes the capsule path", () => {
  const traversing = request();
  traversing.capsule = {
    digest: `sha256:${"b".repeat(64)}`,
    entrypoint: ["/data/capsule/../../bin/sh"],
  };
  assert.throws(
    () => parseExecutionRequest(traversing, brokerLimits, imageBuildId),
    /without traversal/,
  );
});
