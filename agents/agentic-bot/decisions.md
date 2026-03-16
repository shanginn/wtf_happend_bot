# Decisions

## D1: Use RespondDecision tool as forced first call
- **Decision**: Use ToolChoice::specific to force the LLM to call RespondDecision before doing anything else
- **Reason**: The tool already exists with good logic. Forcing it ensures the bot always makes an explicit decision. If shouldRespond=false, skip the agentic loop entirely.
- **Alternatives**: (1) Separate lightweight LLM call for triage - too slow/expensive. (2) Regex/keyword-based mention detection - not smart enough for implicit mentions. (3) Let LLM output empty string - unreliable, wastes tokens.
- **Consequences**: Two-phase approach per message batch: first decide, then execute. Minimal token cost for non-responding turns.

## D2: Accumulate multiple updates before deciding
- **Decision**: Flush all queued updates, save them, prepare context, THEN decide once
- **Reason**: Multiple messages may arrive in rapid succession. Better to see the full picture before deciding.
- **Consequences**: Slight delay but better decisions.

## D3: User Memory stored in PostgreSQL via new Entity
- **Decision**: Create a UserMemory entity in the database, with a save_memory and recall_memory tool
- **Reason**: Persistent, survives workflow continue-as-new, queryable, no external dependencies
- **Alternatives**: (1) MEM0 API - env key exists but adds external dependency. (2) File-based - fragile. (3) Workflow state only - lost on continue-as-new.
- **Consequences**: Need new entity + migration + activity methods + tool definitions.

## D4: Keep chat messages buffer as simple text array in workflow
- **Decision**: Store last N messages as a simple structured array [{user, text, date}] in the workflow, separate from LLM history
- **Reason**: LLM history contains assistant/tool messages. A clean chat buffer is more useful for context and survives across decide/respond phases.
- **Consequences**: Need to build this from contextItems returned by prepareContext.
