import path from "node:path";

import {
  assertImageBuildId,
  type BrokerLimits,
} from "./contracts.js";

export type BrokerConfig = {
  host: string;
  port: number;
  token: string;
  capsuleRoot: string;
  stateRoot: string;
  runtimeRoot: string;
  imagePath: string;
  imageBuildId: string;
  maxConcurrentRuns: number;
  maxRequestBytes: number;
  limits: BrokerLimits;
};

export function loadConfig(env: NodeJS.ProcessEnv = process.env): BrokerConfig {
  const token = required(env, "SANDBOX_BROKER_TOKEN");
  if (Buffer.byteLength(token, "utf8") < 32) {
    throw new Error("SANDBOX_BROKER_TOKEN must contain at least 32 bytes");
  }

  return {
    host: env.SANDBOX_BROKER_HOST?.trim() || "127.0.0.1",
    port: integer(env, "SANDBOX_BROKER_PORT", 8787, 1, 65535),
    token,
    capsuleRoot: absoluteDirectory(env, "SANDBOX_CAPSULE_ROOT"),
    stateRoot: absoluteDirectory(env, "SANDBOX_STATE_ROOT"),
    runtimeRoot: absoluteDirectory(env, "SANDBOX_RUNTIME_ROOT"),
    imagePath: absoluteDirectory(env, "SANDBOX_GONDOLIN_IMAGE_PATH"),
    imageBuildId: assertImageBuildId(
      required(env, "SANDBOX_GONDOLIN_IMAGE_BUILD_ID"),
    ),
    maxConcurrentRuns: integer(
      env,
      "SANDBOX_MAX_CONCURRENT_RUNS",
      4,
      1,
      128,
    ),
    maxRequestBytes: integer(
      env,
      "SANDBOX_MAX_REQUEST_BYTES",
      1024 * 1024,
      1024,
      16 * 1024 * 1024,
    ),
    limits: {
      maxTimeoutMs: integer(
        env,
        "SANDBOX_MAX_TIMEOUT_MS",
        120_000,
        1_000,
        3_600_000,
      ),
      maxStdoutBytes: integer(
        env,
        "SANDBOX_MAX_STDOUT_BYTES",
        1024 * 1024,
        0,
        64 * 1024 * 1024,
      ),
      maxStderrBytes: integer(
        env,
        "SANDBOX_MAX_STDERR_BYTES",
        1024 * 1024,
        0,
        64 * 1024 * 1024,
      ),
      maxOutputBytes: integer(
        env,
        "SANDBOX_MAX_OUTPUT_BYTES",
        16 * 1024 * 1024,
        0,
        1024 * 1024 * 1024,
      ),
      maxOutputFiles: integer(
        env,
        "SANDBOX_MAX_OUTPUT_FILES",
        128,
        0,
        10_000,
      ),
      maxMemoryMiB: integer(
        env,
        "SANDBOX_MAX_MEMORY_MIB",
        1024,
        64,
        32 * 1024,
      ),
      maxCpus: integer(env, "SANDBOX_MAX_CPUS", 4, 1, 64),
    },
  };
}

function required(env: NodeJS.ProcessEnv, name: string): string {
  const value = env[name]?.trim();
  if (!value) {
    throw new Error(`${name} is required`);
  }
  return value;
}

function absoluteDirectory(env: NodeJS.ProcessEnv, name: string): string {
  const value = required(env, name);
  if (!path.isAbsolute(value)) {
    throw new Error(`${name} must be an absolute path`);
  }
  return path.resolve(value);
}

function integer(
  env: NodeJS.ProcessEnv,
  name: string,
  fallback: number,
  minimum: number,
  maximum: number,
): number {
  const raw = env[name]?.trim();
  if (!raw) {
    return fallback;
  }
  const value = Number(raw);
  if (!Number.isSafeInteger(value) || value < minimum || value > maximum) {
    throw new Error(`${name} must be an integer between ${minimum} and ${maximum}`);
  }
  return value;
}
