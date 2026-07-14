# T-43 — Structured roadmap: DB-derived 3-level Roadmap tab

## Goal
The Roadmap tab renders **DB entities** (Milestone → Task) as a 3-level
accordion — milestone (status dot + colour) → task (status + colour) → task
detail (description/goal) — NOT raw markdown. The markdown (`roadmap.md`) is
only an INPUT to `RoadmapSync`; the screen only ever reads DB rows. The
Architect starts writing `roadmap.md` in the structured (nested-task) format,
and the parser also tolerates the legacy prose format so existing projects
render milestones immediately.

## Edit targets
- app/Agents/Architect/ArchitectService.php   (PLAN_PROMPT only + one sync call — see guardrails)
- app/Projects/Roadmap/RoadmapSync.php
- app/Models/Task.php
- database/migrations/2026_07_16_000013_add_description_to_tasks_table.php (new)
- app/Livewire/ProjectWorkspace.php
- resources/views/livewire/partials/project-roadmap.blade.php
- tests/Feature/RoadmapSyncTest.php
- docs/SPEC.md

## Read context (--read, verified)
- app/Models/Milestone.php
- app/Models/Project.php
- app/Projects/Memory/MemoryStore.php
- app/Enums/TaskStatus.php
- agents/ROADMAP.md

## Spec

### 1. Migration — tasks.description
`add_description_to_tasks_table`: add `description` (text, nullable). Down: drop it.
Add `'description'` to `Task::$fillable` (Task.php IS editable this task).

### 2. RoadmapSync — source + parser
2a. **Source precedence** in `sync()`:
   - If `{$project->repo_path}/agents/ROADMAP.md` exists → use its contents (dogfooding).
   - Else read the project's memory roadmap via
     `app(MemoryStore::class)->read($project, 'roadmap.md')`.
   - If neither yields non-empty content → no-op (return).
2b. **Parser tolerates BOTH milestone header styles** (case-insensitive):
   - `## M<N> — <title>`  (structured; N may have a letter suffix)
   - `## Milestone <N>: <title>`  (legacy prose)
   Milestone `key` = normalized `M<N>` (e.g. "Milestone 3" → "M3"; "M11" → "M11").
   position = document order.
2c. **Tasks** come only from checkbox lines `- [<mark>] <T-NN> — <title>`
   (mark ` `/`~`/`x` → todo/ongoing/done). Non-checkbox lines between a
   milestone header and its next header/checkbox accumulate into the
   milestone `summary` (trim; legacy `- description` bullets included).
2d. **description sync**: for each task, if `tasks/<task_key>/task.md` exists in
   memory (`MemoryStore::read`), store its contents in `tasks.description`;
   else leave null. Do this in the same upsert (idempotent — only write an
   event if declared_status/effective changes, NOT for description-only diffs).
2e. Everything else (upsert by key, roadmap_events on real deltas, effective
   status, no touching live `status`) stays as built in T-41.

### 3. ArchitectService — GUARDRAILS (minimal, delicate)
Change ONLY the `roadmap_md` description line inside `PLAN_PROMPT` and add a
short format example. Do NOT change the JSON keys, `first_task_id`,
`first_task_md`, `architecture_md`, `summary`, or any approvePlan logic EXCEPT
adding, at the very end of the successful approvePlan branch (after the
plan.written event), a single line:
`app(\App\Projects\Roadmap\RoadmapSync::class)->for($project)->sync();`
(RoadmapSync::for is static → call `RoadmapSync::for($project)->sync();`.)

New `roadmap_md` spec text in the prompt:
```
"roadmap_md": "markdown roadmap. Each milestone is a header '## M<N> — <title>' followed by one summary line, then its tasks as checkbox items '- [ ] T-00N — <task title>'. The first task MUST appear as the first checkbox and match first_task_id. Example:\n## M1 — Skeleton\nStand up the project shell.\n- [ ] T-001 — Create repo structure\n- [ ] T-002 — Add build system",
```

### 4. ProjectWorkspace
- `getRoadmapProperty()` already syncs + returns milestones with tasks +
  effective status. EXTEND each task entry to include `'description' =>
  $t->description` (the DB column).
- REMOVE the `planText` dependency from the Roadmap view (see §5). Keep the
  `getPlanTextProperty` method (Overview tab still uses it) — only the Roadmap
  partial changes.

### 5. project-roadmap.blade.php — 3-level DB accordion
- DELETE the "Agreed Plan" raw-markdown blob accordion at the top.
- Render `$this->roadmap` (DB-derived). For each milestone: an accordion
  header with status dot (done→bg-ok, ongoing→bg-status-working,
  todo→bg-status-idle), `M-key` + title, `(done/total)` count, chevron.
  Expanded: the milestone summary (if any) + the task accordions.
- Each task = a NESTED accordion: header row with tri-state dot, `T-key` mono
  + title; expanded body shows the task `description` (whitespace-pre-wrap,
  mono, small) or a muted "No task brief yet." when null.
- Empty-state when no milestones.
- Alpine client-side toggles only; no inline styles; Tailwind classes only.

### 6. Tests
- Parser: structured format → milestones + tasks + declared_status (existing).
- NEW: legacy `## Milestone N: Title` + `- desc` prose → milestone with summary,
  zero tasks, no error.
- NEW: description synced from `tasks/<key>/task.md` when present; null when absent.
- NEW: description-only change on re-sync writes NO roadmap_event (idempotent).
- ArchitectServiceTest + plan-flow tests (SessionGrouping, ProjectWorkspace,
  ExecutionUi) MUST stay green — if a scripted plan response needs it, the new
  roadmap_md format is backward-tolerant so they should not need changes.

## Hard constraints
- Roadmap screen reads DB only — never renders raw md (owner requirement).
- Task.php IS editable here (add description to fillable — the T-41 fillable trap).
- ArchitectService: touch ONLY PLAN_PROMPT text + the one sync call. Nothing else.
- Sync writes declared_status/milestone_id/position/title/description — never
  the live `status` column. Effective status unchanged (T-41).
- No inline styles; Alpine client-side toggles; no extra Livewire round-trips.
- Full suite green; no network in tests.

## Acceptance (reviewer runs)
1. `./vendor/bin/pest` green (esp. Architect/plan-flow tests).
2. Wire-test: sync test-joac (legacy roadmap.md) → 5 milestone rows in DB, tab
   shows 5 milestone accordions (no textarea blob). Sync the majordom
   self-project (structured agents/ROADMAP.md) → milestones + nested tasks,
   task unfold shows description where task.md exists.
3. Grep: no `style=` in project-roadmap.blade.php; no raw `roadmap.md` render
   in the Roadmap partial.
4. SPEC §10.1 updated: DB-derived rendering, dual-format parser, memory source,
   description sync.

## Note for later (DO NOT build now) — T-44 metrics
Per-milestone / per-task metrics (consensus rounds, tokens by role, human
interventions, rework cycles, files changed, tests added, time-to-completion)
are a deferred analytics layer. All source data already exists (UsageRecord,
Event, Task.revision, Execution timestamps); per-milestone aggregation becomes
possible once tasks link to milestones (this task). Design the schema so a
`metrics` computation can attach to Milestone/Task later. Owner-requested,
not in scope here.
