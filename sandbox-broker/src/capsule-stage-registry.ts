import { createHash, randomUUID } from "node:crypto";
import { link, mkdir, readFile, rm, writeFile } from "node:fs/promises";
import path from "node:path";

import type { CapsuleStageResult } from "./contracts.js";
import { RunConflictError } from "./run-registry.js";

type StageRecord = {
  schema: 1;
  idempotencyKey: string;
  requestSha256: string;
  result: CapsuleStageResult;
  createdAt: string;
};

type InFlightStage = {
  requestSha256: string;
  promise: Promise<CapsuleStageResult>;
};

export class CapsuleStageRegistry {
  private readonly inFlight = new Map<string, InFlightStage>();

  constructor(private readonly stateRoot: string) {}

  execute(
    idempotencyKey: string,
    requestSha256: string,
    operation: () => Promise<CapsuleStageResult>,
  ): Promise<CapsuleStageResult> {
    const active = this.inFlight.get(idempotencyKey);
    if (active) {
      return active.requestSha256 === requestSha256
        ? active.promise
        : Promise.reject(new RunConflictError(idempotencyKey));
    }

    const execution = this.executeOnce(
      idempotencyKey,
      requestSha256,
      operation,
    );
    let promise!: Promise<CapsuleStageResult>;
    promise = execution.finally(() => {
      if (this.inFlight.get(idempotencyKey)?.promise === promise) {
        this.inFlight.delete(idempotencyKey);
      }
    });
    this.inFlight.set(idempotencyKey, { requestSha256, promise });
    return promise;
  }

  private async executeOnce(
    idempotencyKey: string,
    requestSha256: string,
    operation: () => Promise<CapsuleStageResult>,
  ): Promise<CapsuleStageResult> {
    const stored = await this.readRecord(idempotencyKey);
    if (stored) {
      if (stored.requestSha256 !== requestSha256) {
        throw new RunConflictError(idempotencyKey);
      }
      return stored.result;
    }

    const result = await operation();
    await this.writeRecord({
      schema: 1,
      idempotencyKey,
      requestSha256,
      result,
      createdAt: new Date().toISOString(),
    });
    return result;
  }

  private async readRecord(idempotencyKey: string): Promise<StageRecord | null> {
    const raw = await readFile(this.recordPath(idempotencyKey), "utf8").catch(
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
    const record = JSON.parse(raw) as Partial<StageRecord>;
    if (
      record.schema !== 1 ||
      record.idempotencyKey !== idempotencyKey ||
      typeof record.requestSha256 !== "string" ||
      !record.result ||
      typeof record.result.digest !== "string" ||
      !Array.isArray(record.result.entrypoint)
    ) {
      throw new Error("corrupt capsule stage record");
    }
    return record as StageRecord;
  }

  private async writeRecord(record: StageRecord): Promise<void> {
    const directory = path.join(this.stateRoot, "capsule-stages");
    await mkdir(directory, { recursive: true, mode: 0o700 });
    const target = this.recordPath(record.idempotencyKey);
    const temporary = `${target}.${process.pid}.${randomUUID()}.tmp`;
    try {
      await writeFile(temporary, JSON.stringify(record), {
        encoding: "utf8",
        mode: 0o600,
        flag: "wx",
      });
      try {
        await link(temporary, target);
      } catch (error) {
        if ((error as NodeJS.ErrnoException).code !== "EEXIST") {
          throw error;
        }
        const existing = await this.readRecord(record.idempotencyKey);
        if (existing?.requestSha256 !== record.requestSha256) {
          throw new RunConflictError(record.idempotencyKey);
        }
      }
    } finally {
      await rm(temporary, { force: true }).catch(() => undefined);
    }
  }

  private recordPath(idempotencyKey: string): string {
    const name = createHash("sha256").update(idempotencyKey).digest("hex");
    return path.join(this.stateRoot, "capsule-stages", `${name}.json`);
  }
}
