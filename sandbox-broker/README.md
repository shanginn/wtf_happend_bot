# Gondolin sandbox broker

This service is the only component allowed to start Gondolin Firecracker VMs.
The PHP bot sends a versioned JSON request and receives a bounded, auditable
result. A capsule never receives the broker token, Telegram/model credentials,
database access, or a host repository checkout.

The adapter is pinned to `shanginn/gondolin-firecracker` commit
`10b510625dde73cbfd15ac2fc1ae7b8ef642c62c`. `scripts/vendor-gondolin.sh`
downloads that exact archive, verifies its SHA-256, writes the commit marker,
installs its locked dependencies, and builds the SDK. The Docker build performs
the same commit-pinned source build. The API is isolated in
`src/gondolin-adapter.ts`, so a future Gondolin update stays explicit.

## Runtime invariants

- One fresh VM per run, with explicit `start()` and unconditional `close()`.
- Read-only base rootfs and verified read-only capsule copy.
- Only `/data/scratch` and `/data/output` are writable and both start empty.
- Guest egress is always disabled (`network.mode` only accepts `deny`).
- Stdin contains JSON input; no secret or caller-controlled env API exists.
- Stdout/stderr are continuously drained but retained only to byte limits.
- Timeout, explicit cancellation, and shutdown close the entire VM.
- Capsule/release references are SHA-256 identifiers. The guest assets are an
  absolute directory whose manifest must match a configured immutable build ID.
- A `runId` can be retried with the same request. Reuse with different input
  returns HTTP 409.
- Terminal results and content-addressed output objects persist below
  `SANDBOX_STATE_ROOT`.

## Capsule store

Capsules live at:

```text
${SANDBOX_CAPSULE_ROOT}/sha256/<64-lowercase-hex>/
```

The directory name must equal the broker's canonical tree digest: SHA-256 over
sorted records containing the POSIX relative path, byte size, executable flag,
and file SHA-256.
Symlinks and non-regular files are rejected. Calculate it with:

```bash
pnpm build
pnpm hash-capsule /path/to/capsule
```

The entrypoint must be executable and is invoked directly as argv, never through
a shell. It reads JSON from stdin and may write outputs below
`$SANDBOX_OUTPUT_DIR`.

## HTTP contract

Every `/v1` call requires `Authorization: Bearer <token>`. Keep the listener on
loopback or a private service network; terminate TLS or mTLS before it whenever
traffic leaves the host.

`POST /v1/runs:execute` also requires `Idempotency-Key` equal to `runId`:

```json
{
  "apiVersion": "sandbox.wtf/v1",
  "runId": "dream-space42-20260812-01",
  "spaceId": "space42",
  "release": {
    "id": "release-17",
    "digest": "sha256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
  },
  "capsule": {
    "digest": "sha256:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb",
    "entrypoint": ["/data/capsule/run"]
  },
  "runtime": {
    "imageBuildId": "00000000-0000-4000-8000-000000000000"
  },
  "input": {"task": "evaluate"},
  "limits": {
    "timeoutMs": 30000,
    "maxStdoutBytes": 262144,
    "maxStderrBytes": 262144,
    "maxOutputBytes": 4194304,
    "maxOutputFiles": 32,
    "memoryMiB": 256,
    "cpus": 1
  },
  "network": {"mode": "deny"}
}
```

`runtime.imageBuildId` is copied into the immutable Space release. The broker
accepts the run only when that pin exactly matches its configured Gondolin guest
image, so rotating the image cannot silently change an already-qualified
capsule release.

`DELETE /v1/runs/{runId}` requests cancellation. `GET /healthz` is an
unauthenticated process-health check; it does not claim KVM/image readiness.

Dream code is staged separately with `POST /v1/capsules:stage` and
`Idempotency-Key: <proposalId>:<name>`:

```json
{
  "apiVersion": "sandbox.wtf/v1",
  "proposalId": "proposal-17",
  "spaceId": "space42",
  "name": "daily-summary",
  "language": "javascript",
  "source": "let data = ''; for await (const chunk of process.stdin) data += chunk;\nconsole.log(JSON.stringify(JSON.parse(data)));\n",
  "entrypoint": "run.mjs"
}
```

Source is limited to 65536 UTF-8 bytes. The endpoint only validates and stores
an immutable capsule; it never starts a VM, installs dependencies, grants
network, or activates a release. The broker adds a fixed Node shebang and
returns:

```json
{
  "digest": "sha256:<canonical-tree-digest>",
  "entrypoint": ["/data/capsule/run.mjs"]
}
```

The PHP boundary is `Bot\Space\Sandbox\SandboxBrokerInterface`. Construct its
HTTP implementation with:

```php
$broker = new HttpSandboxBrokerClient(
    baseUri: $_ENV['SANDBOX_BROKER_URL'],
    token: $_ENV['SANDBOX_BROKER_TOKEN'],
);
```

## Build and verify

```bash
corepack enable
./scripts/vendor-gondolin.sh
pnpm install:gondolin
pnpm build:gondolin
pnpm install --frozen-lockfile
pnpm typecheck
pnpm test
pnpm build
```

Copy `.env.example` into the deployment secret/config mechanism; never commit
the resulting values.

## Firecracker host prerequisites

- Linux/KVM and broker access to `/dev/kvm`.
- Firecracker through `GONDOLIN_FIRECRACKER` or `PATH`.
- A pre-provisioned guest asset directory mounted read-only. Its
  `manifest.json.buildId` must equal `SANDBOX_GONDOLIN_IMAGE_BUILD_ID`.
- Writable state/runtime volumes and a read-only capsule volume.
- A filesystem or container ephemeral-storage quota on the runtime volume. The
  broker rejects oversized output when collecting artifacts, but Gondolin's
  threat model does not promise protection from complete storage DoS by a guest.
- A short writable `GONDOLIN_RUNTIME_DIR` for Unix sockets.
- A non-root service account with only the required KVM device group.
- cgroup, namespace, seccomp, filesystem, and pod/node isolation around
  Firecracker. Never mount a Docker socket or application secrets.

The included Dockerfile supplies Node, Python, and `iproute2`; deployment must
still mount Firecracker, the guest asset directory, volumes, and `/dev/kvm`.
No network capabilities are needed for deny-only guest execution.
