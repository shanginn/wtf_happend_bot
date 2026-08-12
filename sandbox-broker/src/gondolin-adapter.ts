import { chmod, lstat, mkdir, mkdtemp, readdir, rm } from "node:fs/promises";
import { readFileSync } from "node:fs";
import path from "node:path";
import { Writable } from "node:stream";

import {
  MemoryProvider,
  ReadonlyProvider,
  RealFSProvider,
  VM,
  loadAssetManifest,
  type VirtualProvider,
} from "@earendil-works/gondolin";

import { sha256HexFromReference } from "./contracts.js";
import type {
  CapturedOutput,
  SandboxArtifact,
  SandboxExecutionRequest,
  SandboxRunStatus,
} from "./contracts.js";
import { ArtifactError, TreeStore } from "./tree-store.js";

export const GONDOLIN_COMMIT =
  "10b510625dde73cbfd15ac2fc1ae7b8ef642c62c" as const;

export type GondolinRunResult = {
  status: SandboxRunStatus;
  exitCode: number | null;
  signal: number | null;
  stdout: CapturedOutput;
  stderr: CapturedOutput;
  artifacts: SandboxArtifact[];
  vmId: string | null;
  capsuleSha256: string;
  error: null | { code: string; message: string };
};

export type GondolinRunnerConfig = {
  capsuleRoot: string;
  stateRoot: string;
  runtimeRoot: string;
  imagePath: string;
  imageBuildId: string;
};

export interface SandboxRunner {
  run(
    request: SandboxExecutionRequest,
    signal: AbortSignal,
  ): Promise<GondolinRunResult>;
}

export class GondolinRunner implements SandboxRunner {
  private readonly treeStore: TreeStore;

  constructor(private readonly config: GondolinRunnerConfig) {
    this.treeStore = new TreeStore(config.capsuleRoot, config.stateRoot);
    const sourceCommit = readFileSync(
      path.resolve(
        import.meta.dirname,
        "../vendor/gondolin-firecracker/.git-commit",
      ),
      "utf8",
    ).trim();
    if (sourceCommit !== GONDOLIN_COMMIT) {
      throw new Error("Gondolin source commit marker does not match the broker adapter");
    }
    const manifest = loadAssetManifest(config.imagePath);
    if (manifest?.buildId !== config.imageBuildId) {
      throw new Error(
        "Gondolin image manifest buildId does not match SANDBOX_GONDOLIN_IMAGE_BUILD_ID",
      );
    }
  }

  async run(
    request: SandboxExecutionRequest,
    cancellationSignal: AbortSignal,
  ): Promise<GondolinRunResult> {
    await mkdir(this.config.runtimeRoot, { recursive: true, mode: 0o700 });
    const runRoot = await mkdtemp(
      path.join(this.config.runtimeRoot, `${request.runId}-`),
    );
    const capsuleRoot = path.join(runRoot, "capsule");
    const outputRoot = path.join(runRoot, "output");
    let vm: VM | null = null;
    let vmClosed = false;
    let capsuleSha256 = sha256HexFromReference(request.capsule.digest);

    const stdout = new BoundedCollector(request.limits.maxStdoutBytes);
    const stderr = new BoundedCollector(request.limits.maxStderrBytes);
    const runController = new AbortController();
    let timedOut = false;
    const forwardCancellation = () =>
      runController.abort(cancellationSignal.reason ?? new Error("run cancelled"));
    if (cancellationSignal.aborted) {
      forwardCancellation();
    } else {
      cancellationSignal.addEventListener("abort", forwardCancellation, {
        once: true,
      });
    }
    const timeout = setTimeout(() => {
      timedOut = true;
      runController.abort(new Error("sandbox execution timed out"));
    }, request.limits.timeoutMs);
    timeout.unref();

    try {
      capsuleSha256 = await this.treeStore.materializeCapsule(
        request.capsule.digest,
        capsuleRoot,
      );
      await mkdir(outputRoot, { mode: 0o700 });

      // Use a pre-provisioned absolute asset path whose manifest build ID was
      // verified in the constructor. This never fetches a mutable image ref.
      vm = new VM({
        autoStart: false,
        rootfs: { mode: "readonly" },
        sandbox: {
          vmm: "firecracker",
          imagePath: this.config.imagePath,
          netEnabled: false,
          allowWebSockets: false,
          autoRestart: false,
          console: "none",
          maxStdinBytes: Math.max(
            64 * 1024,
            Buffer.byteLength(JSON.stringify(request.input), "utf8"),
          ),
          maxQueuedExecs: 1,
        },
        vfs: {
          fuseMount: "/data",
          // Deliberately omit a "/" provider. The FUSE root is a synthetic
          // directory, so the guest cannot discover or mutate runRoot through
          // an unscoped fallback path. Only these three named mounts exist.
          mounts: createRunMounts(capsuleRoot, outputRoot),
        },
        memory: `${request.limits.memoryMiB}M`,
        cpus: request.limits.cpus,
        sessionLabel: `space=${request.spaceId} run=${request.runId}`,
        debugLog: null,
      });

      await raceAbort(vm.start(), runController.signal);
      const execution = await vm.exec(request.capsule.entrypoint, {
        cwd: "/data/capsule",
        env: {
          SANDBOX_RUN_ID: request.runId,
          SANDBOX_SPACE_ID: request.spaceId,
          SANDBOX_RELEASE_ID: request.release.id,
          SANDBOX_OUTPUT_DIR: "/data/output",
          SANDBOX_SCRATCH_DIR: "/data/scratch",
        },
        stdin: JSON.stringify(request.input),
        signal: runController.signal,
        stdout,
        stderr,
        windowBytes: 256 * 1024,
      });

      // Kill any background work before trusting and hashing output files.
      await vm.close();
      vmClosed = true;
      const artifacts = await this.treeStore.persistOutputs(
        outputRoot,
        request.limits.maxOutputFiles,
        request.limits.maxOutputBytes,
      );

      return {
        status: execution.exitCode === 0 ? "completed" : "failed",
        exitCode: execution.exitCode,
        signal: execution.signal ?? null,
        stdout: stdout.result(),
        stderr: stderr.result(),
        artifacts,
        vmId: vm.id,
        capsuleSha256,
        error:
          execution.exitCode === 0
            ? null
            : {
                code: "capsule_exit_nonzero",
                message: `capsule exited with code ${execution.exitCode}`,
              },
      };
    } catch (error) {
      const status: SandboxRunStatus = timedOut
        ? "timed_out"
        : cancellationSignal.aborted
          ? "cancelled"
          : "failed";
      return {
        status,
        exitCode: null,
        signal: null,
        stdout: stdout.result(),
        stderr: stderr.result(),
        artifacts: [],
        vmId: vm?.id ?? null,
        capsuleSha256,
        error: safeError(error, status),
      };
    } finally {
      clearTimeout(timeout);
      cancellationSignal.removeEventListener("abort", forwardCancellation);
      if (vm && !vmClosed) {
        await vm.close().catch(() => undefined);
      }
      await makeTreeWritableForCleanup(runRoot).catch(() => undefined);
      await rm(runRoot, { recursive: true, force: true }).catch(() => undefined);
    }
  }
}

/** @internal Exported for provider-level security tests. */
export function createRunMounts(
  capsuleRoot: string,
  outputRoot: string,
): Record<string, VirtualProvider> {
  return {
    "/capsule": new ReadonlyProvider(new RealFSProvider(capsuleRoot)),
    "/scratch": new MemoryProvider(),
    "/output": new RealFSProvider(outputRoot),
  };
}

async function makeTreeWritableForCleanup(root: string): Promise<void> {
  const info = await lstat(root);
  if (info.isSymbolicLink()) {
    return;
  }
  if (!info.isDirectory()) {
    await chmod(root, 0o600);
    return;
  }

  // Restore owner access before descending; guest-created directories may have
  // removed every permission bit.
  await chmod(root, 0o700);
  const entries = await readdir(root);
  for (const entry of entries) {
    await makeTreeWritableForCleanup(path.join(root, entry));
  }
}

class BoundedCollector extends Writable {
  private readonly chunks: Buffer[] = [];
  private seen = 0;
  private captured = 0;

  constructor(private readonly maximum: number) {
    super();
  }

  override _write(
    chunk: Buffer | string,
    encoding: BufferEncoding,
    callback: (error?: Error | null) => void,
  ): void {
    const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk, encoding);
    this.seen += buffer.length;
    const remaining = Math.max(0, this.maximum - this.captured);
    if (remaining > 0) {
      const captured = buffer.subarray(0, remaining);
      this.chunks.push(captured);
      this.captured += captured.length;
    }
    callback();
  }

  result(): CapturedOutput {
    return {
      text: Buffer.concat(this.chunks).toString("utf8"),
      bytesSeen: this.seen,
      bytesCaptured: this.captured,
      truncated: this.seen > this.captured,
    };
  }
}

async function raceAbort<T>(promise: Promise<T>, signal: AbortSignal): Promise<T> {
  if (signal.aborted) {
    throw signal.reason;
  }
  return new Promise<T>((resolve, reject) => {
    const abort = () => reject(signal.reason ?? new Error("operation aborted"));
    signal.addEventListener("abort", abort, { once: true });
    promise.then(resolve, reject).finally(() => {
      signal.removeEventListener("abort", abort);
    });
  });
}

function safeError(
  error: unknown,
  status: SandboxRunStatus,
): { code: string; message: string } {
  if (status === "timed_out") {
    return { code: "timeout", message: "sandbox execution timed out" };
  }
  if (status === "cancelled") {
    return { code: "cancelled", message: "sandbox execution was cancelled" };
  }
  if (error instanceof ArtifactError) {
    return { code: "artifact_invalid", message: error.message };
  }
  return { code: "sandbox_failure", message: "sandbox execution failed" };
}
