# Agentic Bot Progress

## Current Phase: Phase 5 - Testing (COMPLETED)

## Phases

### Phase 1: Mention Detection & Selective Responding - DONE
- Wired RespondDecision tool as forced first call via ToolChoice::useTool()
- Bot now does a decision pass before entering the agentic loop
- If shouldRespond=false, bot silently observes (only updates history)
- If shouldRespond=true, enters the full agentic loop with tools

### Phase 2: Recent Messages Buffer in Workflow Memory - DONE
- Added chatBuffer: rolling array of last 50 messages [{date, user, text}]
- Deduplication logic prevents duplicate entries
- Buffer maintained across update cycles within the workflow
- Separate from LLM history (which has assistant/tool messages)

### Phase 3: User Memory Tool (DatabaseActivity) - DONE
- Created UserMemory entity with Cycle ORM annotations
- Created UserMemoryRepository with search, findByUser, findByChat
- Created DatabaseActivity with saveMemory and recallMemory methods
- Created SaveMemory tool (user_identifier, category, content)
- Created RecallMemory tool (user_identifier, query)
- Registered in declarations.php and temporal.php data converter

### Phase 4: Additional Tools & Skills - DONE
- Memory tools (save_memory, recall_memory) added
- Updated system prompt with memory_usage instructions
- Added buildToolsPrompt() to RouterActivity for tool descriptions
- Tools wired into workflow's executeTool() method via match expression

### Phase 5: Testing - DONE
- 31 tests, 66 assertions, all passing
- Tests cover: MemoryTools, RespondDecision, UserMemory entity, DatabaseActivity (isSimilar), RouterActivity (prompts), RouterWorkflow (context building, buffer management, history trimming, message queue)

## Files Created
- src/Llm/Tools/Memory/SaveMemory.php
- src/Llm/Tools/Memory/RecallMemory.php
- src/Entity/UserMemory.php
- src/Entity/UserMemory/UserMemoryRepository.php
- src/Activity/DatabaseActivity.php
- tests/Tools/MemoryToolsTest.php
- tests/Tools/RespondDecisionTest.php
- tests/Entity/UserMemoryTest.php
- tests/RouterWorkflow/RouterActivityTest.php
- tests/RouterWorkflow/RouterWorkflowTest.php
- tests/Activity/DatabaseActivityTest.php

## Files Modified
- src/RouterWorkflow/RouterWorkflow.php (major rewrite - decision phase + agentic loop)
- src/RouterWorkflow/RouterActivity.php (new buildToolsPrompt, updated system prompt)
- config/declarations.php (registered DatabaseActivity)
- config/temporal.php (registered SaveMemory, RecallMemory tools)
