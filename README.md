# WTF Happened Bot

A long-lived Telegram agent platform built on PiPH, TrueAsync, PostgreSQL, and
Temporal. Each Telegram conversation has an isolated **Space** with its own
personality, prompt overlay, memories, and skills. Every night, each Space
reviews recent experience in a governed **Dream** and may promote a better
immutable release.

The design follows the separation used by
[Prime Agent](https://github.com/PrimeIntellect-ai/prime-agent): the host owns
execution, policy, evaluation, credentials, and lifecycle while the agent owns
bounded, versioned refinements. This production release is deliberately
no-code: a Space cannot generate, load, or execute programs.

## Space model

A root chat and every Telegram forum topic resolve to separate opaque Space
IDs. They never share prompt state, skills, memory, search results, release
state, or workflow history.

The durable identity lives in PostgreSQL, not in a permanent VM:

```text
Telegram chat/topic
  -> Space binding
  -> active immutable release
       prompt overlay + personality
       versioned skills
       immutable host capability policy
  -> append-only memory revisions
  -> SpaceAgentWorkflowV1
```

One incoming batch pins a complete runtime snapshot: release, model, prompt,
skills, tool schemas, memory revision, and capability-policy digest. Retries
keep that snapshot even if a Dream promotes a new release in the meantime. The
next batch sees the new active release.

The host base prompt and authority boundary are not self-editable. The live
agent cannot mutate its own release. Existing legacy runtime-mutation tools are
excluded from both the model-visible catalog and the execution registry.

## Nightly Dream

Temporal installs one nightly schedule, by default around `03:17` in
`Asia/Yekaterinburg` with up to 30 minutes of jitter. A coordinator starts one
bounded child workflow per eligible Space:

```text
sanitized recent evidence
  -> author candidate from the first time slice
  -> deterministic validation
  -> independent held-out judge on the later time slice
  -> same-authority gate
  -> atomic compare-and-swap promotion
  -> pointer rollback remains available
```

A candidate may refine the prompt overlay, personality, skills, and memories
within the nightly edit budget. Memory changes are append-only and carry Dream
provenance; updates and forgetting create new revisions. Promotion is rejected
if the release or memory baseline changed while the Dream was running.

The candidate cannot change tests, thresholds, policy, credentials, tool
authority, or promotion logic. High-risk or authority-expanding proposals are
rejected automatically. Evaluations, proposals, and promotion events are
durable and idempotent, so retries reconcile a committed result instead of
silently producing a second outcome.

## No-code trust boundary

The host never exposes a code-execution tool to a Space. Release manifests and
cached runtime snapshots containing executable artifacts fail closed. The
dormant `sandbox-broker` sources and PHP contracts remain in the repository for
a possible future release, but CI, Docker Compose, Helm, and runtime
configuration do not build, deploy, or enable them. Old `SANDBOX_*` environment
variables cannot reactivate code execution.

## Built-in Space tools

- Telegram API schema discovery and same-chat/topic actions
- Same-Space inbound-message search
- SearXNG internet search
- Current time by timezone
- Append, recall, correct, and forget Space memories
- Explicit `stay_silent` completion

Telegram side effects remain host-mediated and idempotent. `/pause`, `/resume`,
and `/clear` resolve the same Space identity and retain the existing
authorization rules.

## Persistence

The Space schema stores bindings, immutable releases, versioned skills,
append-only memories, pinned batch snapshots, Dream runs, proposals,
evaluations, and promotions. Database constraints keep release and component
references inside one Space; immutable-content triggers prevent release history
from being rewritten.

The Space migration deliberately makes a clean runtime cut. It preserves
legacy chat-level skills and participant memories by importing them into each
chat's root Space. Topic Spaces start isolated instead of inheriting chat-wide
private state. The old tables remain for recovery, but Space v2 does not read
them at runtime.

## Requirements

- PHP 8.6 ZTS with `ext-true_async >= 0.8.2` and `ext-temporal`
- PostgreSQL 15+ and Temporal Server
- a Telegram bot token and DeepSeek API key
- SearXNG for internet search

## Local setup

```bash
cp .env.sample .env
composer install --ignore-platform-req=php+
temporal server start-dev
docker compose up -d
```

The narrow Composer override ignores only dependency upper bounds that have
not yet declared PHP 8.6 support. Minimum PHP and extension requirements remain
enforced by the production image.

Core settings:

```dotenv
TELEGRAM_BOT_TOKEN=
DEEPSEEK_API_KEY=
TEMPORAL_ADDRESS=host.docker.internal:7233
TEMPORAL_NAMESPACE=default
BOT_INSTANCE_ID=default

DB_HOST=db
DB_PORT=5432
DB_DATABASE=wtf_happend_bot
DB_USERNAME=postgres
DB_PASSWORD=postgres

SPACE_AGENT_TASK_QUEUE=space-agent-v1
SPACE_DREAM_TASK_QUEUE=space-dream-v1
SPACE_DREAM_TIME_ZONE=Asia/Yekaterinburg
SPACE_DREAM_HOUR=3
SPACE_DREAM_MINUTE=17
SPACE_DREAM_JITTER_MINUTES=30
```

The interactive agent and Dream queues must run in separate worker processes:

```bash
WORKER_PACKAGE=space-agent-v1 php src/worker.php
WORKER_PACKAGE=space-dream-v1 php src/worker.php
php src/bot.php
```

Docker Compose already starts those workers separately.

## Clean cutover

No Temporal replay compatibility with the old `AgenticWorkflow` or
`RouterWorkflow` is provided. Terminate those executions, deploy the Space v2
workers, and let the next Telegram update start
`space-agent/{spaceId}/v1/release/{imageDigest}`. The admin commands remain
available for diagnostics, but production release does not depend on an admin
running them:

```bash
php src/space-v2-admin.php cutover
php src/space-v2-admin.php cutover --apply
php src/space-v2-admin.php install-dream-schedule
```

Production CI serializes releases and deploys only an image addressed by its
OCI digest. Before changing the application Deployment it installs and probes a
release-qualified recovery controller. The application uses `Recreate`: the old
Telegram poller stops, the migration/import runs, and the candidate starts with
Telegram ingress gated while both release-qualified Temporal workers are
checked.

Before authorization, a failure or abandoned CI runner durably tombstones the
candidate and restores the exact previous Helm revision. After authorization,
rollback is forbidden: the in-cluster controller repeatedly converges forward
by pausing the old Dream schedule, terminating old workflow families, installing
and verifying the new schedule, and finally opening Telegram polling through a
database-backed activation marker. The controller survives CI loss and retries
transient Kubernetes, PostgreSQL, Telegram, and Temporal failures without an
administrator.

The first legacy adoption uses Helm ownership transfer for the old hook-created
ServiceAccount. Application Pods never mount its Kubernetes token. Recovery is
allowed to recreate only the two exact legacy RoleBindings required by the
previous revision; controller RBAC and its Helm 3.17.3 image are immutable and
kept across rollback.

`Recreate` deliberately prevents old and new Telegram pollers from overlapping.
It does imply a bounded no-poller interval while the old pod stops, the final
legacy state is imported, and the new pod starts; Telegram keeps pending updates
for the new ingress process.

## Verification

```bash
php vendor/bin/phpunit
composer fix
```

The full PHP suite requires the PHP 8.6 TrueAsync runtime. Tests exercising
coroutines also require the `true_async` extension.
