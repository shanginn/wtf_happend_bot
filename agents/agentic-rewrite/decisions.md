# Architectural Decisions

## Decision 1: Use Temporal Agentic Loop Pattern
**Date**: Session 1
**Rationale**: The RouterWorkflow pattern from botrouter provides:
- Async message handling via signals
- Conversation state persistence via workflow history
- Tool-based LLM integration
- Clean separation of concerns (Activities vs Workflows)

**Alternatives Considered**:
- Keep deterministic handlers (rejected - no state persistence, limited flexibility)
- Use message queue only (rejected - no built-in conversation context)

## Decision 2: Skills vs Tools Architecture
**Date**: Session 1
**Decision**: 
- **Skills**: High-level capabilities exposed to the LLM via system prompt (summarization, Q&A)
- **Tools**: Concrete actions the LLM can invoke (fetch_messages, search_messages)

**Rationale**: Matches botrouter pattern where skills define *how* to do something well, and tools provide the *actions*.

## Decision 3: Database Access via Activities
**Date**: Session 1
**Decision**: All database operations go through Temporal Activities
**Rationale**: 
- Activities can be retried on failure
- Clean separation from workflow logic
- Testable in isolation

## Decision 4: Use Cycle ORM with Temporal
**Date**: Session 1
**Decision**: Keep Cycle ORM for message persistence
**Rationale**: Existing database schema and entities work well; no need to change

## Decision 5: One Workflow Per Chat (Not Per Chat+User)
**Date**: Session 1
**Decision**: Single workflow per chat, not per chat+user combination
**Rationale**: 
- Chat summarization is chat-wide, not user-specific
- Simpler state management
- All users in same chat share context

## Decision 6: Continue-As-New for Event History Limits
**Date**: Session 1
**Decision**: Implement Continue-As-New after 100 processed updates
**Rationale**: 
- Temporal limits: 50 MB / 51,200 events per workflow
- Long-running chats would eventually hit limits
- Continue-As-New provides seamless continuation

**Implementation**:
- `processedCount` tracks total messages processed
- `MAX_UPDATES_BEFORE_CONTINUE = 100`
- History trimmed to last 50 messages
- Summary of conversation passed to new workflow
- Query methods for monitoring: `getProcessedCount()`, `getHistorySize()`
