# Harness² — TypeScript port for Pi

The whole bot, including the evolution engine in `src/harness2/`, runs on
Node.js 24+. There is no interpreter bridge or external optimizer process.

This is a **modified local TypeScript port**, not an official Google release or
an unmodified upstream package. It derives from Google Research
[Harness²](https://github.com/google-research/harness2/tree/6868817518876de8fd170c6a8354daca273b6191),
commit `6868817518876de8fd170c6a8354daca273b6191`, copyright 2026 Google LLC,
under Apache-2.0. `LICENSE` contains the original license. Each derived module
identifies the port, and `upstream-reference.json` records the original source
revision and hashes for provenance; it is not a manifest of shipped source files.

## What was ported

- Parallel mode: k baseline executions, 2k independent INV/DOM proposals and
  their probes, then k independent compositions and their probes.
- Sequential mode: the same baseline evidence, then k rounds of INV/DOM
  proposals followed by composition, carrying the preceding round's harness
  and refinement history forward.
- The ledger identity of **4k probes and 3k editing stages**, with k restricted
  to 1 or 2 in this application. Selection and reflection are separate calls.
- One correction attempt for a structurally rejected proposal, the editable
  tree and automatic skill triggers, source evidence and grader-leak checks.
- Deterministic anonymous candidate IDs, selection of an existing delivered
  output, reflection, and the cumulative per-chat experience library.

Like the previous bot adapter, the selection pool includes delivered baseline
and intermediate candidates. An improvement is not forced. Invalid selection
fails the attempt; it does not silently activate a fallback. The original
research suite's finals-only selection convention is not the bot's convention.

The bot's transport was serial; the port preserves that default. Parallel mode
describes independent candidate derivation, not unbounded provider concurrency.
Pi supplies model calls, and generated scripts execute only in Pi Code Mode's
QuickJS environment with bounded memory and time.

## Adaptations

Evidence is an in-memory map with the previous `harness/`, `rollouts/`, `pool/`
and `candidates/` paths. There are no simulated terminal workspaces. Prompts are
adapted to the group-chat task and the actual Markdown/JS/TS editable surface.
Diff evidence contains full changed files rather than compact difflib hunks.
The generic benchmark renderers, external CLI executors, hooks and plugin
surfaces are not part of this port. Grader markers are also checked in TS files.

Library versions preserve the previous domain/INDEX.md and
harnesses/task/EXPERIENCE.md layout. Reads are bounded and reject symlinks.
`Learning` copies the accepted library into staging; only a candidate passing
independent checks publishes the library and chat skill pointer atomically.

The host reserves a fixed shared-budget allowance before paid calls, enforces
per-call and total limits and stops at the deadline even if a provider ignores
cancellation. Unconfirmed cost consumes the reserved ceiling conservatively.
The engine implementation version participates in cached-task fingerprints, so
an old task cannot silently be executed under a different engine version.

## Verification

`test/fixtures/harness2-upstream-parity.json` contains deterministic observations
captured from the previously pinned upstream engine before removing it.
TypeScript tests compare both modes at k=1 and k=2, stage order and dependencies,
anonymous selection, baseline retention and failure/retry behavior against those
fixtures. No original-language runtime is required to run them.

The suite also exercises actual isolated JS/TS skill computation, library
retention, the independent activation gate, deadline handling and spend limits.
Model calls remain fixtures: these checks establish the tested algorithm
contracts, not improved quality in a live conversation or equivalence for every
possible research benchmark.
