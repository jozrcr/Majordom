# T-40 — Exchange trace (contract detail view)

## Goal
A condensed, per-execution view of the hand-offs between actors
(architect → builder → reviewer → …): who told whom what, with an excerpt and
expandable full text, instead of hunting raw logs. Derived as a **projection
over the existing `events` table** (+ `Task.description` for the instruction
content + `UsageRecord` for per-role tokens/cost). NO new logging pipeline, no
log parsing, no LLM summaries.

## Edit targets
- app/Projects/Exchanges/ExchangeTrace.php (new — the projection service)
- app/Livewire/ProjectWorkspace.php
- resources/views/livewire/project-workspace.blade.php
- resources/views/livewire/partials/project-exchanges.blade.php (new)
- tests/Feature/ExchangeTraceTest.php (new)
- docs/SPEC.md

## Read context (--read, verified)
- app/Models/Event.php
- app/Models/Execution.php
- app/Models/Task.php
- app/Models/UsageRecord.php
- app/Livewire/ProjectWorkspace.php

## Spec

### 1. ExchangeTrace service
`ExchangeTrace::for(Execution $execution): array` — returns an ordered
(by event id) list of Exchange rows, each an assoc array:
`['from' => string, 'to' => string, 'kind' => string, 'excerpt' => string,
'full' => string, 'at' => \Illuminate\Support\Carbon]`.

Build it by walking `$execution->events()->orderBy('id')->get()` (add an
`events()` hasMany on Execution if missing — Event has execution_id) and mapping
each event to zero-or-one exchange via this table (from_actor = event.actor
unless noted). Events not in the table (workflow.started, *.started control
markers, delegate.completed) are SKIPPED.

| event name | from → to | kind | content source |
|---|---|---|---|
| task.delegated / delegate.started | architect → builder | instruction | the execution's Task.description (first task with a non-null description; else "(brief pending)") |
| build.completed | builder → reviewer | result | payload.summary + "\nFiles: " + implode(', ', payload.filesChanged ?? []) |
| build.failed | builder → owner | failure | payload.summary ?? payload.reason ?? "Build failed" |
| test.completed | tests → reviewer | result | payload (json) or "Tests ran" if empty |
| review.completed | reviewer → builder | verdict | payload.verdict ?? payload.reason ?? "Approved" |
| review.retry / review.failed | reviewer → builder | rework | payload.reason ?? "Changes requested" |
| human_review.waiting_human | reviewer → owner | clarification | "Awaiting human review" |
| consensus.message | architect → owner | consensus | payload.excerpt ?? "(consensus turn)" |
| question.answered | owner → architect | clarification | payload.answer ?? "(answered)" |
| commit.applied | owner → system | commit | payload.message ?? "Committed" |

- `excerpt` = first ~200 chars of `full` (mb_strimwidth or Str::limit), single-lined.
- Deduplicate the instruction: emit the architect→builder `instruction`
  exchange only ONCE per execution (first delegate event), even though build
  loops repeat — the rework verdicts carry the iteration story.
- Never throw on missing payload keys — use null-coalescing everywhere.

### 2. Per-role usage summary
`ExchangeTrace::usageFor(Execution $execution): array` — `UsageRecord` for the
execution grouped by role → `['role' => ['prompt_tokens'=>, 'completion_tokens'=>,
'cost_usd'=>]]`. Single query. Used for a small header strip.

### 3. ProjectWorkspace
- Add `'exchanges'` to allowed tabs (chat|overview|stats|roadmap|exchanges);
  keep mount()/updatedTab() normalization.
- `public ?int $selectedExecutionId = null;`
- `getExecutionsProperty()` → project executions, latest first (id + created_at
  + status) for the picker.
- `getExchangesProperty(): array` → resolve the selected execution (or latest);
  return `['execution' => Execution|null, 'usage' => ..., 'rows' => ExchangeTrace::for(...)]`
  (empty rows when no execution).

### 4. project-exchanges.blade.php
- Execution picker (select bound to `selectedExecutionId`, `wire:model.live`)
  when >1 execution; header shows execution status + the per-role usage strip
  (role: in/out tokens, $cost).
- Then the exchange rows as a vertical list of cards. Each card:
  - from-chip → to-chip (actor colour: architect/builder/reviewer/owner/system/tests),
    a `kind` badge, and the timestamp (diffForHumans).
  - the `excerpt`, and an Alpine accordion (client-side) to reveal `full`
    (whitespace-pre-wrap mono). `cursor-pointer`, hover affordance.
- Empty-state when no execution / no rows.
- Tailwind classes only, NO inline styles.

### 5. Tests (ExchangeTraceTest, class-based like the other tab tests)
- Seed a Project + Execution + a Task (with description) + a scripted event
  sequence (delegate.started, build.completed w/ summary+filesChanged,
  review.retry w/ reason, review.completed) via Event::create.
- Assert ExchangeTrace::for returns rows in order with the right from/to/kind
  and that the instruction appears exactly once; excerpt ≤ ~200 chars.
- Assert usageFor groups UsageRecord by role.
- Livewire: 'exchanges' is an allowed tab; unknown tab still resets to chat.

## Hard constraints
- Projection only — read events/Task/UsageRecord; do NOT modify EventRecorder,
  the workflow engine, or any emission seam.
- No new columns, no migration.
- Null-safe on every payload access; never throw on a sparse execution.
- Alpine client-side expand; no extra Livewire round-trips; no inline styles.
- Full suite green; no network in tests.

## Acceptance (reviewer runs)
1. `./vendor/bin/pest` green.
2. Wire-test: ExchangeTrace::for(a real execution, e.g. test-joac exec 13/14)
   returns a sensible ordered trace (instruction once, build results, review
   rework/verdict).
3. Grep: no `style=` in project-exchanges.blade.php.
4. SPEC §10.2 documents the exchange trace (projection, mapping, per-execution).
