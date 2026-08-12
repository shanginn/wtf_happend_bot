import path from "node:path";

export const API_VERSION = "sandbox.wtf/v1" as const;

const IDENTIFIER = /^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/;
const SHA256_REFERENCE = /^sha256:([a-f0-9]{64})$/;
const IMAGE_BUILD_ID =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

export type BrokerLimits = {
  maxTimeoutMs: number;
  maxStdoutBytes: number;
  maxStderrBytes: number;
  maxOutputBytes: number;
  maxOutputFiles: number;
  maxMemoryMiB: number;
  maxCpus: number;
};

export type SandboxExecutionRequest = {
  apiVersion: typeof API_VERSION;
  runId: string;
  spaceId: string;
  release: {
    id: string;
    digest: string;
  };
  capsule: {
    digest: string;
    entrypoint: string[];
  };
  runtime: {
    imageBuildId: string;
  };
  input: unknown;
  limits: {
    timeoutMs: number;
    maxStdoutBytes: number;
    maxStderrBytes: number;
    maxOutputBytes: number;
    maxOutputFiles: number;
    memoryMiB: number;
    cpus: number;
  };
  network: {
    mode: "deny";
  };
};

export type CapsuleStageRequest = {
  apiVersion: typeof API_VERSION;
  proposalId: string;
  spaceId: string;
  name: string;
  language: "javascript";
  source: string;
  entrypoint: string;
};

export type CapsuleStageResult = {
  digest: string;
  entrypoint: string[];
};

export type CapturedOutput = {
  text: string;
  bytesSeen: number;
  bytesCaptured: number;
  truncated: boolean;
};

export type SandboxArtifact = {
  path: string;
  ref: string;
  sha256: string;
  sizeBytes: number;
};

export type SandboxRunStatus =
  | "completed"
  | "failed"
  | "timed_out"
  | "cancelled";

export type SandboxExecutionResult = {
  apiVersion: typeof API_VERSION;
  runId: string;
  status: SandboxRunStatus;
  exitCode: number | null;
  signal: number | null;
  stdout: CapturedOutput;
  stderr: CapturedOutput;
  artifacts: SandboxArtifact[];
  error: null | {
    code: string;
    message: string;
  };
  audit: {
    requestSha256: string;
    capsuleSha256: string;
    releaseDigest: string;
    imageBuildId: string;
    gondolinCommit: string;
    vmId: string | null;
    startedAt: string;
    finishedAt: string;
    durationMs: number;
  };
};

export class ContractError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "ContractError";
  }
}

export function parseExecutionRequest(
  value: unknown,
  brokerLimits: BrokerLimits,
  expectedImageBuildId: string,
): SandboxExecutionRequest {
  const root = object(value, "request");
  exactKeys(root, [
    "apiVersion",
    "runId",
    "spaceId",
    "release",
    "capsule",
    "runtime",
    "input",
    "limits",
    "network",
  ], "request");

  if (root.apiVersion !== API_VERSION) {
    throw new ContractError(`apiVersion must be ${API_VERSION}`);
  }

  const runId = identifier(root.runId, "runId");
  const spaceId = identifier(root.spaceId, "spaceId");

  const release = object(root.release, "release");
  exactKeys(release, ["id", "digest"], "release");
  const releaseId = identifier(release.id, "release.id");
  const releaseDigest = sha256Reference(release.digest, "release.digest");

  const capsule = object(root.capsule, "capsule");
  exactKeys(capsule, ["digest", "entrypoint"], "capsule");
  const capsuleDigest = sha256Reference(capsule.digest, "capsule.digest");
  const entrypoint = stringArray(capsule.entrypoint, "capsule.entrypoint");
  if (entrypoint.length === 0 || entrypoint.length > 64) {
    throw new ContractError("capsule.entrypoint must contain 1 to 64 entries");
  }
  if (!entrypoint[0]?.startsWith("/data/capsule/")) {
    throw new ContractError(
      "capsule.entrypoint[0] must be an absolute /data/capsule path",
    );
  }
  if (
    path.posix.normalize(entrypoint[0]) !== entrypoint[0] ||
    entrypoint[0].includes("\\")
  ) {
    throw new ContractError(
      "capsule.entrypoint[0] must be a normalized POSIX path without traversal",
    );
  }
  for (const [index, argument] of entrypoint.entries()) {
    if (argument.length === 0 || argument.length > 4096 || argument.includes("\0")) {
      throw new ContractError(
        `capsule.entrypoint[${index}] must be a non-empty string without NUL bytes`,
      );
    }
  }

  const runtime = object(root.runtime, "runtime");
  exactKeys(runtime, ["imageBuildId"], "runtime");
  if (typeof runtime.imageBuildId !== "string") {
    throw new ContractError("runtime.imageBuildId must be a string");
  }
  const imageBuildId = assertImageBuildId(
    runtime.imageBuildId,
    "runtime.imageBuildId",
  );
  if (imageBuildId !== expectedImageBuildId) {
    throw new ContractError(
      "runtime.imageBuildId does not match the broker's immutable Gondolin image",
    );
  }

  const limits = object(root.limits, "limits");
  exactKeys(
    limits,
    [
      "timeoutMs",
      "maxStdoutBytes",
      "maxStderrBytes",
      "maxOutputBytes",
      "maxOutputFiles",
      "memoryMiB",
      "cpus",
    ],
    "limits",
  );

  const timeoutMs = boundedInteger(
    limits.timeoutMs,
    1,
    brokerLimits.maxTimeoutMs,
    "limits.timeoutMs",
  );
  const maxStdoutBytes = boundedInteger(
    limits.maxStdoutBytes,
    0,
    brokerLimits.maxStdoutBytes,
    "limits.maxStdoutBytes",
  );
  const maxStderrBytes = boundedInteger(
    limits.maxStderrBytes,
    0,
    brokerLimits.maxStderrBytes,
    "limits.maxStderrBytes",
  );
  const maxOutputBytes = boundedInteger(
    limits.maxOutputBytes,
    0,
    brokerLimits.maxOutputBytes,
    "limits.maxOutputBytes",
  );
  const maxOutputFiles = boundedInteger(
    limits.maxOutputFiles,
    0,
    brokerLimits.maxOutputFiles,
    "limits.maxOutputFiles",
  );
  const memoryMiB = boundedInteger(
    limits.memoryMiB,
    64,
    brokerLimits.maxMemoryMiB,
    "limits.memoryMiB",
  );
  const cpus = boundedInteger(
    limits.cpus,
    1,
    brokerLimits.maxCpus,
    "limits.cpus",
  );

  const network = object(root.network, "network");
  exactKeys(network, ["mode"], "network");
  if (network.mode !== "deny") {
    throw new ContractError("network.mode must be deny");
  }

  assertJsonValue(root.input, "input");

  return {
    apiVersion: API_VERSION,
    runId,
    spaceId,
    release: { id: releaseId, digest: releaseDigest },
    capsule: { digest: capsuleDigest, entrypoint },
    runtime: { imageBuildId },
    input: root.input,
    limits: {
      timeoutMs,
      maxStdoutBytes,
      maxStderrBytes,
      maxOutputBytes,
      maxOutputFiles,
      memoryMiB,
      cpus,
    },
    network: { mode: "deny" },
  };
}

export function parseCapsuleStageRequest(value: unknown): CapsuleStageRequest {
  const root = object(value, "request");
  exactKeys(
    root,
    [
      "apiVersion",
      "proposalId",
      "spaceId",
      "name",
      "language",
      "source",
      "entrypoint",
    ],
    "request",
  );
  if (root.apiVersion !== API_VERSION) {
    throw new ContractError(`apiVersion must be ${API_VERSION}`);
  }

  const proposalId = identifier(root.proposalId, "proposalId");
  const spaceId = identifier(root.spaceId, "spaceId");
  if (
    typeof root.name !== "string" ||
    !/^[a-z][a-z0-9-]{0,63}$/.test(root.name)
  ) {
    throw new ContractError(
      "name must be a lowercase slug containing at most 64 characters",
    );
  }
  if (root.language !== "javascript") {
    throw new ContractError("language must be javascript");
  }
  if (typeof root.source !== "string" || root.source.length === 0) {
    throw new ContractError("source must be a non-empty string");
  }
  const sourceBytes = Buffer.byteLength(root.source, "utf8");
  if (sourceBytes > 64 * 1024) {
    throw new ContractError("source must not exceed 65536 UTF-8 bytes");
  }
  if (root.source.includes("\0")) {
    throw new ContractError("source must not contain NUL bytes");
  }
  if (root.source.startsWith("#!")) {
    throw new ContractError(
      "source must not provide a shebang; the broker adds the fixed Node launcher",
    );
  }
  const entrypoint = relativeJavascriptEntrypoint(root.entrypoint);

  return {
    apiVersion: API_VERSION,
    proposalId,
    spaceId,
    name: root.name,
    language: "javascript",
    source: root.source,
    entrypoint,
  };
}

export function sha256HexFromReference(reference: string): string {
  const match = SHA256_REFERENCE.exec(reference);
  if (!match?.[1]) {
    throw new ContractError("invalid sha256 reference");
  }
  return match[1];
}

export function assertImageBuildId(
  value: string,
  label = "SANDBOX_GONDOLIN_IMAGE_BUILD_ID",
): string {
  if (!IMAGE_BUILD_ID.test(value)) {
    throw new ContractError(
      `${label} must be an immutable Gondolin build UUID`,
    );
  }
  return value;
}

function object(value: unknown, label: string): Record<string, unknown> {
  if (value === null || typeof value !== "object" || Array.isArray(value)) {
    throw new ContractError(`${label} must be an object`);
  }
  return value as Record<string, unknown>;
}

function exactKeys(
  value: Record<string, unknown>,
  allowed: string[],
  label: string,
): void {
  const allowedSet = new Set(allowed);
  for (const key of Object.keys(value)) {
    if (!allowedSet.has(key)) {
      throw new ContractError(`${label} contains unknown field ${key}`);
    }
  }
  for (const key of allowed) {
    if (!Object.hasOwn(value, key)) {
      throw new ContractError(`${label}.${key} is required`);
    }
  }
}

function identifier(value: unknown, label: string): string {
  if (typeof value !== "string" || !IDENTIFIER.test(value)) {
    throw new ContractError(`${label} is not a valid identifier`);
  }
  return value;
}

function sha256Reference(value: unknown, label: string): string {
  if (typeof value !== "string" || !SHA256_REFERENCE.test(value)) {
    throw new ContractError(`${label} must be sha256:<64 lowercase hex>`);
  }
  return value;
}

function stringArray(value: unknown, label: string): string[] {
  if (!Array.isArray(value) || value.some((entry) => typeof entry !== "string")) {
    throw new ContractError(`${label} must be an array of strings`);
  }
  return [...value] as string[];
}

function relativeJavascriptEntrypoint(value: unknown): string {
  if (
    typeof value !== "string" ||
    value.length === 0 ||
    value.length > 128 ||
    value.includes("\0") ||
    value.includes("\\") ||
    path.posix.isAbsolute(value) ||
    path.posix.normalize(value) !== value
  ) {
    throw new ContractError(
      "entrypoint must be a normalized relative POSIX path",
    );
  }
  const segments = value.split("/");
  if (
    segments.some(
      (segment) =>
        segment === "" ||
        segment === "." ||
        segment === ".." ||
        !/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/.test(segment),
    ) ||
    (!value.endsWith(".js") && !value.endsWith(".mjs"))
  ) {
    throw new ContractError(
      "entrypoint must contain safe path segments and end in .js or .mjs",
    );
  }
  return value;
}

function boundedInteger(
  value: unknown,
  minimum: number,
  maximum: number,
  label: string,
): number {
  if (
    typeof value !== "number" ||
    !Number.isSafeInteger(value) ||
    value < minimum ||
    value > maximum
  ) {
    throw new ContractError(
      `${label} must be an integer between ${minimum} and ${maximum}`,
    );
  }
  return value;
}

function assertJsonValue(value: unknown, label: string): void {
  try {
    const encoded = JSON.stringify(value);
    if (encoded === undefined) {
      throw new Error("not JSON");
    }
  } catch {
    throw new ContractError(`${label} must be JSON-serializable`);
  }
}
