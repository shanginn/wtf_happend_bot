import { mkdir, readFile, rename, rm, writeFile } from "node:fs/promises";
import path from "node:path";
import { randomUUID } from "node:crypto";

import type { SandboxExecutionResult } from "./contracts.js";

type RunRecord = {
  schema: 1;
  runId: string;
  requestSha256: string;
  state: "running" | "terminal";
  updatedAt: string;
  result?: SandboxExecutionResult;
};

type InFlightRun = {
  requestSha256: string;
  controller: AbortController;
  promise: Promise<SandboxExecutionResult>;
};

export type CancellationStatus =
  | "cancellation_requested"
  | "terminal"
  | "not_found";

export class RunConflictError extends Error {
  constructor(runId: string) {
    super(`runId ${runId} was already used for a different request`);
    this.name = "RunConflictError";
  }
}

export class RunRegistry {
  private readonly inFlight = new Map<string, InFlightRun>();

  constructor(private readonly stateRoot: string) {}

  execute(
    runId: string,
    requestSha256: string,
    operation: (signal: AbortSignal) => Promise<SandboxExecutionResult>,
  ): Promise<SandboxExecutionResult> {
    const active = this.inFlight.get(runId);
    if (active) {
      if (active.requestSha256 !== requestSha256) {
        return Promise.reject(new RunConflictError(runId));
      }
      return active.promise;
    }

    const controller = new AbortController();
    const execution = this.executeOnce(
      runId,
      requestSha256,
      controller.signal,
      operation,
    );
    let promise!: Promise<SandboxExecutionResult>;
    promise = execution.finally(() => {
      if (this.inFlight.get(runId)?.promise === promise) {
        this.inFlight.delete(runId);
      }
    });
    this.inFlight.set(runId, { requestSha256, controller, promise });
    return promise;
  }

  async cancel(runId: string): Promise<CancellationStatus> {
    const active = this.inFlight.get(runId);
    if (active) {
      active.controller.abort(new Error("run cancelled by caller"));
      return "cancellation_requested";
    }

    const stored = await this.readRecord(runId);
    if (!stored) {
      return "not_found";
    }
    return stored.state === "terminal" ? "terminal" : "not_found";
  }

  shutdown(): void {
    for (const active of this.inFlight.values()) {
      active.controller.abort(new Error("sandbox broker is shutting down"));
    }
  }

  private async executeOnce(
    runId: string,
    requestSha256: string,
    signal: AbortSignal,
    operation: (signal: AbortSignal) => Promise<SandboxExecutionResult>,
  ): Promise<SandboxExecutionResult> {
    const stored = await this.readRecord(runId);
    if (stored && stored.requestSha256 !== requestSha256) {
      throw new RunConflictError(runId);
    }
    if (stored?.state === "terminal" && stored.result) {
      return stored.result;
    }
    return this.runAndPersist(runId, requestSha256, signal, operation);
  }

  private async runAndPersist(
    runId: string,
    requestSha256: string,
    signal: AbortSignal,
    operation: (signal: AbortSignal) => Promise<SandboxExecutionResult>,
  ): Promise<SandboxExecutionResult> {
    await this.writeRecord({
      schema: 1,
      runId,
      requestSha256,
      state: "running",
      updatedAt: new Date().toISOString(),
    });

    try {
      const result = await operation(signal);
      await this.writeRecord({
        schema: 1,
        runId,
        requestSha256,
        state: "terminal",
        updatedAt: new Date().toISOString(),
        result,
      });
      return result;
    } catch (error) {
      await rm(this.recordPath(runId), { force: true }).catch(() => undefined);
      throw error;
    }
  }

  private async readRecord(runId: string): Promise<RunRecord | null> {
    const raw = await readFile(this.recordPath(runId), "utf8").catch(
      (error: NodeJS.ErrnoException) => {
        if (error.code === "ENOENT") {
          return null;
        }
        throw error;
      },
    );
    if (raw === null) {
      return null;
    }

    const value = JSON.parse(raw) as Partial<RunRecord>;
    if (
      value.schema !== 1 ||
      value.runId !== runId ||
      typeof value.requestSha256 !== "string" ||
      (value.state !== "running" && value.state !== "terminal")
    ) {
      throw new Error(`corrupt run record for ${runId}`);
    }
    return value as RunRecord;
  }

  private async writeRecord(record: RunRecord): Promise<void> {
    const directory = path.join(this.stateRoot, "runs");
    await mkdir(directory, { recursive: true, mode: 0o700 });
    const target = this.recordPath(record.runId);
    const temporary = `${target}.${process.pid}.${randomUUID()}.tmp`;
    try {
      await writeFile(temporary, JSON.stringify(record), {
        encoding: "utf8",
        mode: 0o600,
        flag: "wx",
      });
      await rename(temporary, target);
    } finally {
      await rm(temporary, { force: true }).catch(() => undefined);
    }
  }

  private recordPath(runId: string): string {
    return path.join(this.stateRoot, "runs", `${runId}.json`);
  }
}
