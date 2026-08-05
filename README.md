# WTF Happened Bot

An autonomous Telegram group-chat agent built on
[PiPH](https://github.com/shanginn/pihp), TrueAsync, and Temporal.

The bot decides whether a message needs a response, gathers context with tools,
performs the requested work, and either acts through the Telegram Bot API or
deliberately stays silent.

## Architecture

Each Telegram chat topic (including the root/general topic) has one long-lived
`AgenticWorkflow`:

1. The durable long-polling ingress reads Telegram updates in `update_id` order.
   It advances the polling offset only after every matching handler has
   completed successfully, so a failed handler is retried before later updates.
2. Accepted updates arrive through `signalWithStart`.
3. The workflow persists and batches updates for five seconds.
4. A `PiPHP.DurableAgent` child workflow runs the PiPH agent loop.
5. Model calls and tools execute only in Temporal activities.
6. The child returns its portable Pi message snapshot to the topic workflow.
7. The topic workflow continues as new after 100 ingested updates.

The agent uses `deepseek/deepseek-v4-flash` by default. Its tool catalog is made
from PiPH `Tool`, `DurableAgentTool`, and `ToolRegistry` primitives; there is no
bot-specific OpenAI client, serializer, decision agent, response agent, or
working-memory loop.

```text
Telegram update
    -> AgenticWorkflow (one per chat topic)
        -> PiPHP.DurableAgent (one child per batch)
            -> model activity
            -> durable tool activities
        -> portable Pi message snapshot
```

### Built-in tools

- Safe Telegram Bot API schema discovery and calls bound to the current topic
- Persisted inbound Telegram-history search
- SearXNG internet search
- Current time by timezone
- Participant memory save, recall, update, and deletion
- Chat-scoped runtime skill and runtime tool management
- Explicit `stay_silent` completion

Mutating tools are marked sequential in the durable workflow. Their stable PiPH
idempotency keys are claimed in PostgreSQL before an external action, and
completed results are reused on activity retry. A second terminal Telegram
action in the same logical batch is suppressed by a separate durable batch
claim, including parent fallbacks after a failed child workflow. Safe Telegram
reads bypass the mutation ledger and can use normal Temporal retries.

The direct confirmation path for `/pause`, `/resume`, and `/clear` uses the
same PostgreSQL ledger with separate command and reply claims derived from the
full canonical Telegram update identity: `update_id`, action, chat, topic, and
message. Authorization and the Temporal mutation are never repeated after an
ambiguous command outcome. A Telegram reply whose request may have been
accepted before the response was lost is likewise not sent again; the failed
handler attempt is retried once, then the persisted ambiguous claim allows the
ingress cursor to advance without risking a duplicate reply.

Only one bot ingress process may long-poll a Telegram token. The shipped Helm
Deployment hardcodes one replica and uses the `Recreate` strategy so old and new
pollers cannot overlap during rollout. Concurrent long-poll bot processes are
unsupported because an incomplete command or reply claim deliberately represents
a conservative ambiguous external outcome.

Model completions are cached after the provider result is stored. A provider
call can still repeat if the worker dies after the provider succeeds but before
that result reaches the database; Temporal cannot make a third-party API
exactly-once.

Idempotency rows must outlive every open workflow and the Temporal namespace's
history-retention window. Operational cleanup may delete only completed tool
rows and model-completion rows after the corresponding workflows are closed and
older than that retention window. Incomplete tool claims require reconciliation
and must not be removed by an automatic age-based job.

### Data handling and guardrails

Routed message text, captions, quoted fragments, participant identity, chat
metadata, and relevant Telegram event metadata are sent to the configured
external DeepSeek model. Structured contact, location, and venue details are
withheld from model context. Telegram image attachments are described by
metadata only; their private bytes are not forwarded to the model.

Inbound updates accepted by the workflow are stored as serialized Telegram
update JSON in PostgreSQL for durable ingestion and history search. That stored
record can contain fields which are deliberately excluded from model context.
Participant memories and chat-scoped runtime capabilities are also stored in
PostgreSQL.

The model-facing Telegram API tool is bound to the current chat and topic. Its
allowlist excludes cross-chat targeting, moderation, webhook and bot
configuration, payments and refunds, and message mutation. Shipping and
pre-checkout queries are rejected because bot payments are disabled.

`/pause`, `/resume`, `/clear`, and model-requested runtime capability mutations
fail closed unless they come from the private-chat user or from identifiable,
non-anonymous owners or administrators in a group, supergroup, or channel.

### Runtime capabilities

The agent may create durable, chat-specific:

- **runtime skills**, which are injected into its system prompt; and
- **runtime tools**, whose stored argument schema is validated by PiPH before a
  separate model completion executes their instructions.

Runtime capabilities cannot replace built-in PHP tools. Definitions are bounded
to 20 skills and 20 tools per chat, 8 KB per body, instructions, or schema, and
a 50 KB enabled-context budget. Runtime skills enter the main system prompt;
runtime tools execute as separate, schema-validated model calls and cannot
directly invoke built-in tools.

## Requirements

- PHP 8.6 ZTS
- `ext-true_async` 0.8.2 or newer
- `ext-temporal`
- PostgreSQL
- Temporal Server
- a Telegram bot token
- a DeepSeek API key

The project uses the TrueAsync branches of Phenogram and the Temporal PHP SDK,
plus the three independent PiPH packages:

- [pihp](https://github.com/shanginn/pihp)
- [pihp-agent-core](https://github.com/shanginn/pihp-agent-core)
- [pihp-ai](https://github.com/shanginn/pihp-ai)

## Setup

```bash
cp .env.sample .env
composer install --ignore-platform-req=php+
```

The temporary `php+` override ignores only dependency upper bounds that have
not yet declared PHP 8.6 support; minimum PHP and extension requirements remain
enforced. The production image uses the same narrow override.

Configure at least:

```dotenv
TELEGRAM_BOT_TOKEN=
DEEPSEEK_API_KEY=
TEMPORAL_ADDRESS=localhost:7233
TEMPORAL_NAMESPACE=default

DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=wtf_happend_bot
DB_USERNAME=postgres
DB_PASSWORD=postgres

SEARCH_BASE_URL=http://localhost:38080
SEARCH_TIMEOUT_SECONDS=10
SEARXNG_SECRET=replace-with-a-random-secret
```

With a Temporal development server already running on the host, start the bot,
worker, PostgreSQL, and SearXNG containers:

```bash
docker compose up -d
```

Or run the processes separately:

```bash
temporal server start-dev
php src/worker.php
php src/bot.php
```

SearXNG is exposed at `http://localhost:38080` by Docker Compose.

## Temporal rollout

This rewrite intentionally does not preserve replay compatibility with workflow
histories produced by the previous agent implementation. Before deploying it,
terminate or reset every open legacy `AgenticWorkflow` execution, and terminate
the retired `RouterWorkflow` executions. Do this before starting the new worker;
the next accepted Telegram update starts a clean PiPH-backed workflow for its
topic.

The production Helm chart expects the Temporal namespace configured by
`TEMPORAL_NAMESPACE` to exist before deployment. Its default is
`wtf-happend-bot`; local development defaults to `default`.

The workflow controls remain:

- `/pause` pauses the current topic; updates received while paused are persisted
  for history/search but are not processed retroactively;
- `/resume` resumes new-message processing in the current topic; and
- `/clear` terminates the current topic workflow.

## Development

```bash
php vendor/bin/phpunit
composer fix
```

The unit test command requires the PHP 8.6 TrueAsync runtime. Tests that exercise
coroutines additionally require the `true_async` extension to be loaded.
