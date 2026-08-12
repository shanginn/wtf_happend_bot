import { performance } from "node:perf_hooks";

import { canonicalSha256 } from "./canonical-json.js";
import {
  API_VERSION,
  type SandboxExecutionRequest,
  type SandboxExecutionResult,
} from "./contracts.js";
import {
  GONDOLIN_COMMIT,
  type SandboxRunner,
} from "./gondolin-adapter.js";
import { RunRegistry, type CancellationStatus } from "./run-registry.js";

export class CapacityError extends Error {
  constructor() {
    super("sandbox broker is at its concurrency limit");
    this.name = "CapacityError";
  }
}

export class ExecutionService {
  private activeRuns = 0;

  constructor(
    private readonly registry: RunRegistry,
    private readonly runner: SandboxRunner,
    private readonly imageBuildId: string,
    private readonly maxConcurrentRuns: number,
  ) {}

  execute(request: SandboxExecutionRequest): Promise<SandboxExecutionResult> {
    const requestSha256 = canonicalSha256(request);
    return this.registry.execute(
      request.runId,
      requestSha256,
      async (signal) => {
        if (this.activeRuns >= this.maxConcurrentRuns) {
          throw new CapacityError();
        }
        this.activeRuns += 1;
        const startedAt = new Date();
        const started = performance.now();
        try {
          const execution = await this.runner.run(request, signal);
          const finishedAt = new Date();
          return {
            apiVersion: API_VERSION,
            runId: request.runId,
            status: execution.status,
            exitCode: execution.exitCode,
            signal: execution.signal,
            stdout: execution.stdout,
            stderr: execution.stderr,
            artifacts: execution.artifacts,
            error: execution.error,
            audit: {
              requestSha256,
              capsuleSha256: execution.capsuleSha256,
              releaseDigest: request.release.digest,
              imageBuildId: this.imageBuildId,
              gondolinCommit: GONDOLIN_COMMIT,
              vmId: execution.vmId,
              startedAt: startedAt.toISOString(),
              finishedAt: finishedAt.toISOString(),
              durationMs: Math.max(0, Math.round(performance.now() - started)),
            },
          };
        } finally {
          this.activeRuns -= 1;
        }
      },
    );
  }

  cancel(runId: string): Promise<CancellationStatus> {
    return this.registry.cancel(runId);
  }

  shutdown(): void {
    this.registry.shutdown();
  }
}
