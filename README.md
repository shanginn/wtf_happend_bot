# WTF Happened Bot - Temporal Agentic Architecture

A Telegram bot that summarizes chat messages and answers questions about chat history using the Temporal Agentic Loop pattern.

## Architecture

This bot uses the **Temporal Agentic Loop** pattern:

- **RouterWorkflow**: Long-running workflow per chat that maintains conversation state
- **RouterActivity**: Processes updates using LLM with skill-based prompts
- **Skills**: Define how to handle different types of requests (summarization, Q&A)
- **Activities**: Execute actions (send messages, LLM calls)

### Continue-As-New Strategy

To avoid Temporal's event history limits (50 MB / 51,200 events), the workflow implements:

1. **Update Counter**: Tracks processed messages
2. **Automatic Continue-As-New**: After 100 updates, the workflow continues as new with:
   - Summarized conversation history
   - Processed count preserved
3. **History Trimming**: Keeps only last 50 messages in memory
4. **Query Methods**: Monitor workflow health:
   - `getProcessedCount()` - Total messages processed
   - `getHistorySize()` - Current history length

### Key Components

```
src/
├── RouterWorkflow/           # Temporal workflow infrastructure
│   ├── RouterWorkflow.php    # Main workflow (one per chat)
│   ├── RouterActivity.php    # LLM processing logic
│   ├── RouterWorkflowHandler.php  # Entry point for updates
│   ├── RouterWorkflowInput.php    # Workflow input DTO
│   └── MessageQueue.php      # Internal message queue
├── Activity/                 # Temporal activities
│   ├── TelegramActivity.php  # Telegram API calls
│   └── LlmActivity.php       # LLM API calls
├── Llm/Skills/              # Skill definitions
│   ├── SkillInterface.php
│   ├── SummarizationSkill.php
│   └── QuestionAnsweringSkill.php
├── Temporal/                # Data converters
│   ├── TelegramDataConverter.php
│   └── OpenaiDataConverter.php
├── Telegram/                # Extended Telegram types
│   ├── Factory.php
│   └── Update.php
├── Entity/                  # Database entities
└── bot.php / worker.php     # Entry points
```

## Skills

The bot has two main skills:

1. **Summarization Skill**: Generates concise summaries of chat conversations, identifying distinct threads and key points.

2. **Question Answering Skill**: Answers specific questions about chat history with contextual insights.

## Setup

### Prerequisites

- PHP 8.4+
- PostgreSQL database
- Temporal Server (running on localhost:7233)

### Installation

```bash
composer install
./vendor/bin/rr get
```

### Configuration

1. Copy `.env.sample` to `.env`:
```bash
cp .env.sample .env
```

2. Edit `.env` with your credentials:
```env
TELEGRAM_BOT_TOKEN=your_bot_token
DEEPSEEK_API_KEY=your_deepseek_api_key
TEMPORAL_CLI_ADDRESS=localhost:7233

# Database
DB_HOST=db
DB_PORT=5432
DB_DATABASE=wtf_happend_bot
DB_USERNAME=postgres
DB_PASSWORD=postgres
```

### Running

#### Local Development (Docker Compose)

```bash
# Start all services (bot, worker, temporal, db)
docker-compose up -d

# View logs
docker-compose logs -f bot worker
```

#### Manual Start (for development)

```bash
# 1. Start Temporal Server (in separate terminal)
temporal server start-dev

# 2. Start the Temporal worker (in separate terminal)
php src/worker.php

# 3. Start the Telegram bot (in separate terminal)
php src/bot.php
```

#### Production Deployment (Kubernetes)

The bot uses Helm for Kubernetes deployment. Both bot and worker run in the same pod.

Required GitHub secrets:
- `TELEGRAM_BOT_TOKEN`
- `DEEPSEEK_API_KEY`
- `DB_PASSWORD`
- `TEMPORAL_CLI_ADDRESS` (e.g., `temporal:7233`)

```bash
# Deploy using Helm
cd helm
helm upgrade wtf-happend-bot --namespace=wtfhappendbot -f values.yaml .
```

## Usage

- Send any message to the bot - it will be processed by the LLM
- Ask for summaries: "What happened in the chat?"
- Ask questions: "What did @user say about X?"

## Migration from Deterministic Handlers

The bot was rewritten from deterministic handlers to the Temporal Agentic pattern:

**Before:**
- `SummarizeCommandHandler`: Handled `/wtf` command deterministically
- `SaveUpdateHandler`: Saved messages to database
- `StartCommandHandler`: Simple greeting

**After:**
- Single `RouterWorkflow` per chat handles all messages
- LLM decides how to respond based on skills
- Conversation history maintained in workflow state
- Async processing via Temporal signals

## Development

### Running Tests

```bash
./vendor/bin/phpunit
```

### Code Style

```bash
composer fix
```
