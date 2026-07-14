# T-44 — Per-milestone / per-task metrics

## Goal
Surface owner-requested delivery metrics per milestone (with per-task
drill-down) in the **Stats tab**: tokens by role, cost, human interventions,
review rework cycles, files changed, time-to-completion. All derived from
EXISTING data (UsageRecord, Event, Task, Execution) — no new logging, no LLM.
Home it in Stats (not Roadmap) to avoid colliding with the Roadmap partial.

## Edit targets
- app/Projects/Metrics/MilestoneMetrics.php (new — the computation service)
- app/Livewire/ProjectWorkspace.php
- resources/views/livewire/partials/project-stats.blade.php
- tests/Feature/MilestoneMetricsTest.php (new)
- docs/SPEC.md

## Read context (--read, verified)
- app/Models/Milestone.php
- app/Models/Task.php
- app/Models/Execution.php
- app/Models/UsageRecord.php
- app/Models/Event.php

## Data model facts (verified — build against these)
- `Milestone` hasMany `Task` (milestone_id, T-43). Roadmap-synced tasks that
  never ran have `execution_id = null` → they contribute zeros.
- `Task.execution_id` → the Execution that ran it (nullable, single).
- `UsageRecord`: project_id, execution_id, role, prompt_tokens,
  completion_tokens, cost_usd.
- `Event`: execution_id, name, actor, payload. Relevant names:
  `review.retry`/`review.failed` (rework), `approval.granted`,
  `question.answered`, `human_review.waiting_human`,
  `human_task.waiting_human` (human interventions), `build.completed`
  (payload.filesChanged[]).
- `Task.revision` also tracks rework count.

## Spec

### 1. MilestoneMetrics service
`MilestoneMetrics::forMilestone(Milestone $m): array` and
`MilestoneMetrics::forTask(Task $t): array`, each returning:
```
[
  'tokens' => ['architect'=>int,'builder'=>int,'reviewer'=>int],   // prompt+completion summed per role
  'cost_usd' => float,
  'human_interventions' => int,
  'rework_cycles' => int,
  'files_changed' => int,
  'time_to_completion' => ?int,   // seconds, null if not computable
  'tests_added' => null,          // DEFERRED — always null for now (render as "—")
]
```
Computation (all null-safe, scoped to the relevant execution ids):
- A milestone's execution ids = its tasks' non-null `execution_id`s (distinct).
  A task's = its own `execution_id` (or empty).
- `tokens`: `UsageRecord::whereIn('execution_id', $ids)->groupBy('role')` →
  sum(prompt+completion) per role. Missing role → 0.
- `cost_usd`: sum cost_usd over those UsageRecords.
- `human_interventions`: count Events in those executions whose name is in the
  human set above.
- `rework_cycles`: count Events named `review.retry`/`review.failed` in those
  executions. (For a single task, prefer `max(0, $task->revision - 1)` when it
  is larger — revisions and retries can diverge; take the max.)
- `files_changed`: distinct file paths across all `build.completed`
  payload.filesChanged in those executions (count of the union set).
- `time_to_completion`: for the execution id set, `max(events.created_at) -
  min(events.created_at)` in seconds; null when no events.
- Every query scoped/aggregated — no N+1 across tasks (batch by execution ids).

### 2. ProjectWorkspace
- `getMilestoneMetricsProperty(): array` → the project's milestones (ordered by
  position, eager-load tasks) each as
  `['key'=>, 'title'=>, 'status'=>deriveStatus, 'metrics'=>forMilestone,
    'tasks'=>[ ['key'=>,'title'=>,'metrics'=>forTask], ... ] ]`.
  Reuse the existing roadmap sync guard so metrics reflect current milestones
  (it is fine to read milestones that `getRoadmapProperty` already synced; do
  NOT re-run sync here — just query Milestone rows).

### 3. project-stats.blade.php
Append a "Per-milestone metrics" section BELOW the existing usage/execution
blocks:
- One row/card per milestone: status dot + `M-key` + title, then a compact
  metric grid: Tokens (A/B/R), Cost, Human, Rework, Files, Time.
  Format tokens with `number_format`; cost as `$0.0000`; time as `Xm Ys` (or
  `—` when null); `tests_added` renders `—`.
- Alpine accordion per milestone → per-task metric rows (same columns) for its
  tasks. Client-side toggle, cursor-pointer, hover affordance.
- Empty-state "No milestones yet." when none.
- Tailwind classes only, NO inline styles.

### 4. Tests (MilestoneMetricsTest, class-based)
- Seed Project + Milestone + 2 Tasks (one linked to an Execution with
  UsageRecords for architect/builder/reviewer + a build.completed event with
  filesChanged + a review.retry event; one task with no execution).
- Assert forMilestone aggregates tokens by role, cost, files_changed (distinct
  union), rework_cycles, human_interventions; time_to_completion non-null when
  events exist.
- Assert forTask on the ran task matches; forTask on the never-ran task is all
  zeros / null time.
- Livewire: getMilestoneMetricsProperty returns milestone rows with metrics.

## Hard constraints
- Read-only projection — no new columns, no migration, no changes to
  EventRecorder / UsageLedger / the engine.
- `tests_added` is explicitly deferred (always null / "—"). Do NOT attempt diff
  parsing.
- Null-safe on sparse data (never-run tasks, missing payload keys).
- No N+1 (aggregate by execution-id sets, not per-task queries in a loop).
- No inline styles; Alpine client-side drill-down; full suite green; no network.

## Acceptance (reviewer runs)
1. `./vendor/bin/pest` green.
2. Wire-test: forMilestone on a real milestone of the majordom/test-joac
   project returns sane aggregates (tokens where executions exist, zeros where
   not).
3. Grep: no `style=` in project-stats.blade.php.
4. SPEC §10.3 documents the metrics projection + the deferred tests_added column.

## Note (later, DO NOT build): consensus rounds / architect planning tokens are
project-level (before per-milestone execution) — surface those in the Stats
header separately if wanted; they are NOT per-milestone metrics.
