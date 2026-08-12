import { createHash, timingSafeEqual } from "node:crypto";
import { mkdir } from "node:fs/promises";
import http, {
  type IncomingMessage,
  type Server,
  type ServerResponse,
} from "node:http";

import { loadConfig, type BrokerConfig } from "./config.js";
import { CapsuleService } from "./capsule-service.js";
import { CapsuleStageRegistry } from "./capsule-stage-registry.js";
import {
  ContractError,
  parseCapsuleStageRequest,
  parseExecutionRequest,
} from "./contracts.js";
import { CapacityError, ExecutionService } from "./execution-service.js";
import { GondolinRunner } from "./gondolin-adapter.js";
import { RunConflictError, RunRegistry } from "./run-registry.js";

export function createBrokerServer(config: BrokerConfig): Server {
  const registry = new RunRegistry(config.stateRoot);
  const capsuleService = new CapsuleService(
    new CapsuleStageRegistry(config.stateRoot),
    config.capsuleRoot,
    config.stateRoot,
  );
  const runner = new GondolinRunner({
    capsuleRoot: config.capsuleRoot,
    stateRoot: config.stateRoot,
    runtimeRoot: config.runtimeRoot,
    imagePath: config.imagePath,
    imageBuildId: config.imageBuildId,
  });
  const service = new ExecutionService(
    registry,
    runner,
    config.imageBuildId,
    config.maxConcurrentRuns,
  );

  const server = http.createServer((request, response) => {
    void route(request, response, config, service, capsuleService).catch((error: unknown) => {
      writeFailure(response, error);
    });
  });
  server.requestTimeout = config.limits.maxTimeoutMs + 30_000;
  server.headersTimeout = 15_000;
  server.keepAliveTimeout = 5_000;
  server.maxRequestsPerSocket = 100;

  const shutdown = () => {
    service.shutdown();
    server.close(() => process.exit(0));
  };
  process.once("SIGTERM", shutdown);
  process.once("SIGINT", shutdown);

  return server;
}

async function route(
  request: IncomingMessage,
  response: ServerResponse,
  config: BrokerConfig,
  service: ExecutionService,
  capsuleService: CapsuleService,
): Promise<void> {
  response.setHeader("Cache-Control", "no-store");
  response.setHeader("Content-Type", "application/json; charset=utf-8");
  response.setHeader("X-Content-Type-Options", "nosniff");

  const url = new URL(request.url ?? "/", "http://sandbox-broker.invalid");
  if (request.method === "GET" && url.pathname === "/healthz") {
    writeJson(response, 200, { status: "ok" });
    return;
  }

  authenticate(request, config.token);

  if (request.method === "POST" && url.pathname === "/v1/capsules:stage") {
    const contentType = request.headers["content-type"]?.split(";", 1)[0];
    if (contentType !== "application/json") {
      throw new HttpError(415, "unsupported_media_type", "expected application/json");
    }
    const body = await readJson(request, config.maxRequestBytes);
    const stage = parseCapsuleStageRequest(body);
    const idempotencyKey = singleHeader(request, "idempotency-key");
    const expectedKey = `${stage.proposalId}:${stage.name}`;
    if (!idempotencyKey || idempotencyKey !== expectedKey) {
      throw new HttpError(
        400,
        "invalid_idempotency_key",
        "Idempotency-Key must exactly match proposalId:name",
      );
    }

    const result = await capsuleService.stage(stage, idempotencyKey);
    log({
      event: "capsule_staged",
      proposalId: stage.proposalId,
      spaceId: stage.spaceId,
      name: stage.name,
      digest: result.digest,
    });
    writeJson(response, 200, result);
    return;
  }

  if (request.method === "POST" && url.pathname === "/v1/runs:execute") {
    const contentType = request.headers["content-type"]?.split(";", 1)[0];
    if (contentType !== "application/json") {
      throw new HttpError(415, "unsupported_media_type", "expected application/json");
    }
    const body = await readJson(request, config.maxRequestBytes);
    const execution = parseExecutionRequest(
      body,
      config.limits,
      config.imageBuildId,
    );
    const idempotencyKey = singleHeader(request, "idempotency-key");
    if (!idempotencyKey || idempotencyKey !== execution.runId) {
      throw new HttpError(
        400,
        "invalid_idempotency_key",
        "Idempotency-Key must exactly match runId",
      );
    }

    const result = await service.execute(execution);
    log({
      event: "sandbox_run_terminal",
      runId: result.runId,
      status: result.status,
      requestSha256: result.audit.requestSha256,
      capsuleSha256: result.audit.capsuleSha256,
      durationMs: result.audit.durationMs,
    });
    writeJson(response, 200, result);
    return;
  }

  const cancellation = /^\/v1\/runs\/([A-Za-z0-9][A-Za-z0-9._:-]{0,127})$/.exec(
    url.pathname,
  );
  if (request.method === "DELETE" && cancellation?.[1]) {
    const runId = cancellation[1];
    const status = await service.cancel(runId);
    if (status === "not_found") {
      throw new HttpError(404, "run_not_found", "run was not found");
    }
    writeJson(response, status === "cancellation_requested" ? 202 : 200, {
      runId,
      status,
    });
    return;
  }

  throw new HttpError(404, "not_found", "route was not found");
}

function authenticate(request: IncomingMessage, expectedToken: string): void {
  const authorization = singleHeader(request, "authorization");
  const prefix = "Bearer ";
  const received = authorization?.startsWith(prefix)
    ? authorization.slice(prefix.length)
    : "";
  const expectedDigest = createHash("sha256").update(expectedToken).digest();
  const receivedDigest = createHash("sha256").update(received).digest();
  if (!timingSafeEqual(expectedDigest, receivedDigest) || received === "") {
    throw new HttpError(401, "unauthorized", "invalid bearer token");
  }
}

async function readJson(
  request: IncomingMessage,
  maximumBytes: number,
): Promise<unknown> {
  const contentLength = Number(request.headers["content-length"] ?? 0);
  if (Number.isFinite(contentLength) && contentLength > maximumBytes) {
    throw new HttpError(413, "request_too_large", "request body is too large");
  }

  const chunks: Buffer[] = [];
  let bytes = 0;
  for await (const chunk of request) {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
    bytes += buffer.length;
    if (bytes > maximumBytes) {
      throw new HttpError(413, "request_too_large", "request body is too large");
    }
    chunks.push(buffer);
  }

  try {
    return JSON.parse(Buffer.concat(chunks).toString("utf8"));
  } catch {
    throw new HttpError(400, "invalid_json", "request body is not valid JSON");
  }
}

function singleHeader(
  request: IncomingMessage,
  name: string,
): string | undefined {
  const value = request.headers[name];
  return Array.isArray(value) ? value[0] : value;
}

function writeFailure(response: ServerResponse, error: unknown): void {
  if (response.headersSent) {
    response.destroy();
    return;
  }

  if (error instanceof HttpError) {
    writeJson(response, error.status, {
      error: { code: error.code, message: error.message },
    });
    return;
  }
  if (error instanceof ContractError) {
    writeJson(response, 400, {
      error: { code: "invalid_request", message: error.message },
    });
    return;
  }
  if (error instanceof RunConflictError) {
    writeJson(response, 409, {
      error: { code: "run_conflict", message: error.message },
    });
    return;
  }
  if (error instanceof CapacityError) {
    response.setHeader("Retry-After", "1");
    writeJson(response, 429, {
      error: { code: "capacity_exceeded", message: error.message },
    });
    return;
  }

  log({ event: "sandbox_broker_error", error: errorName(error) });
  writeJson(response, 500, {
    error: { code: "internal_error", message: "sandbox broker failed" },
  });
}

function writeJson(response: ServerResponse, status: number, value: unknown): void {
  response.statusCode = status;
  response.end(JSON.stringify(value));
}

function log(value: Record<string, unknown>): void {
  process.stdout.write(`${JSON.stringify({ ...value, at: new Date().toISOString() })}\n`);
}

function errorName(error: unknown): string {
  return error instanceof Error ? error.name : "UnknownError";
}

class HttpError extends Error {
  constructor(
    readonly status: number,
    readonly code: string,
    message: string,
  ) {
    super(message);
    this.name = "HttpError";
  }
}

async function main(): Promise<void> {
  const config = loadConfig();
  await Promise.all([
    mkdir(config.capsuleRoot, { recursive: true, mode: 0o700 }),
    mkdir(config.stateRoot, { recursive: true, mode: 0o700 }),
    mkdir(config.runtimeRoot, { recursive: true, mode: 0o700 }),
  ]);
  const server = createBrokerServer(config);
  server.listen(config.port, config.host, () => {
    log({
      event: "sandbox_broker_listening",
      host: config.host,
      port: config.port,
      imageBuildId: config.imageBuildId,
    });
  });
}

void main().catch((error: unknown) => {
  log({ event: "sandbox_broker_start_failed", error: errorName(error) });
  process.exitCode = 1;
});
