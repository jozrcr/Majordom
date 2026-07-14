# T-41 — Roadmap tab (milestones/tasks synced from ROADMAP.md)

## Goal
A new **Roadmap** tab on the project workspace that renders the project's
milestones → tasks as accordions with tri-state status (todo grey / ongoing
amber / done green). Structure is parsed from `{repo_path}/agents/ROADMAP.md`
(the md file is the source of truth) and mirrored into the DB on render, so
editing the md updates the roadmap. Live execution status in the DB overrides
the declared status **upward only**. Plus two small Overview polish items.

## Edit targets
- database/migrations/2026_07_16_000010_create_milestones_table.php (new)
- database/migrations/2026_07_16_000011_add_milestone_id_to_tasks_table.php (new)
- database/migrations/2026_07_16_000012_create_roadmap_events_table.php (new)
- app/Models/Milestone.php (new)
- app/Models/RoadmapEvent.php (new)
- app/Projects/Roadmap/RoadmapSync.php (new)
- app/Console/Commands/SyncRoadmap.php (new)
- app/Livewire/ProjectWorkspace.php
- resources/views/livewire/project-workspace.blade.php
- resources/views/livewire/partials/project-roadmap.blade.php (new)
- resources/views/livewire/partials/project-overview.blade.php
- tests/Feature/RoadmapSyncTest.php (new)
- tests/Feature/ProjectTabsTest.php
- docs/SPEC.md

## Read context (--read, paths VERIFIED to exist)
- app/Models/Project.php
- app/Models/Task.php
- app/Enums/TaskStatus.php
- app/Livewire/ProjectWorkspace.php
- resources/views/livewire/partials/project-overview.blade.php
- app/Projects/Memory/MemoryStore.php
- agents/ROADMAP.md

## Spec

### 1. Migrations
- `milestones`: id, project_id (fk, cascade), milestone_key (string, e.g.
  "M11"), title (string), summary (text, nullable), position (unsigned int),
  timestamps. Unique (project_id, milestone_key).
- `tasks`: add `milestone_id` (nullable fk, nullOnDelete), `position`
  (unsigned int, default 0), `declared_status` (string, nullable — the md
  marker: todo|ongoing|done).
- `roadmap_events`: id, project_id (fk, cascade), type (string:
  milestone_added|task_added|task_status_changed|task_removed), subject_key
  (string, e.g. "T-41"), detail (string, nullable, e.g. "ongoing → done"),
  timestamps.

### 2. Milestone model
- fillable: project_id, milestone_key, title, summary, position.
- `project()` belongsTo; `tasks()` hasMany(Task)->orderBy('position').
- `deriveStatus(): string` — 'done' if all tasks done; 'ongoing' if any task
  ongoing (or a mix of done+todo); 'todo' if none started. Empty milestone → 'todo'.

### 3. ROADMAP.md parser (inside RoadmapSync)
Parse rules (already how agents/ROADMAP.md is written — read it):
- `## M<NN> — <title>` → milestone. Key = the `M<NN>` token; position = document order.
- The paragraph text between a milestone header and its first `- [` bullet =
  that milestone's `summary` (trim; may be empty).
- `- [<mark>] <T-NN> — <title>` → task under the current milestone.
  mark `' '`→todo, `~`→ongoing, `x`→done. Key = `T-NN` token (keep the exact
  key incl. suffixes like `T-23a`). position = order within the milestone.
- Ignore all other lines (headers `#`, prose, blank).

### 4. RoadmapSync service
- `RoadmapSync::for(Project $project): self`.
- `sync(): void` — no-op (return early) if `{$project->repo_path}/agents/ROADMAP.md`
  does not exist. Otherwise parse and upsert **within a DB transaction**:
  - Upsert milestones by (project_id, milestone_key): title, summary, position.
  - Upsert tasks by (project_id, task_key): set milestone_id, position,
    title, `declared_status`. Do NOT create an Execution and do NOT touch the
    live `status` column here (that is owned by the harness) — only set
    `declared_status`.
  - New task rows created by sync get `status = TaskStatus::Pending`.
- Idempotent: syncing an unchanged file writes zero `roadmap_events`.
- Emit `roadmap_events` on real deltas only: milestone_added, task_added,
  task_status_changed (detail = "old → new" using the *effective* status,
  see §5), task_removed (task_key no longer in md → detail "removed"; leave
  the Task row but null its milestone_id).

### 5. Effective (tri-state) status — reconciliation
`effectiveStatus(Task): string` used by the view AND by event diffing:
- Map the DB `TaskStatus` to tri-state: any completed/merged-like → done;
  any running/dispatched/in-progress-like → ongoing; pending/failed/other → todo.
  (Read app/Enums/TaskStatus.php and map every case explicitly — no default
  that silently mis-buckets.)
- Map `declared_status` to tri-state directly.
- Effective = **max(dbTriState, declaredTriState)** on ordering todo(0) <
  ongoing(1) < done(2). So the harness can light a task amber/green without a
  md edit, but md can't downgrade a task the DB says is further along.

### 6. Sync-on-render
- In ProjectWorkspace, add `'roadmap'` to the allowed tabs (chat|overview|
  stats|roadmap); keep the mount() + updatedTab() normalization from T-39.
- Add a computed `getRoadmapProperty()` (or a `roadmap` method) that, the
  first time it runs in a request, calls `RoadmapSync::for($this->project)->sync()`
  once (guard with a private bool), then returns the project's milestones
  (with tasks) ordered by position, each task carrying its effective status.
- Add `getRecentRoadmapChangesProperty()` → last 8 RoadmapEvent for the
  project, newest first (for the Overview "recent plan changes" feed).

### 7. artisan command
`majordom:sync-roadmap {project? : slug or id}` — syncs one project (if given)
or all projects; prints a one-line summary per project (milestones/tasks
upserted, events written). For CI/manual use.

### 8. Roadmap partial (project-roadmap.blade.php)
- Top: "Agreed plan" accordion — render the stored approved-plan text (reuse
  the Overview plan data path). Collapsible via Alpine (x-data open state);
  collapsed by default if long. Empty-state when no plan.
- Then milestones in order. Each milestone = an accordion header row:
  status dot (grey/amber/green via deriveStatus), `M-key` + title, and a
  count "(done/total)". `cursor-pointer`, hover affordance, Alpine toggle.
  Expanded body shows the summary (if any) + the task list.
- Each task row: tri-state marker (grey dot / amber dot / green ✓ via
  effectiveStatus), `T-key` mono + title. No inline styles — Tailwind classes only.
- Empty-state when the project has no ROADMAP.md / no milestones.

### 9. Overview polish (project-overview.blade.php)
- **Consensus accordion**: the recent-consensus list becomes accordions —
  collapsed shows role label + a one-line excerpt with `cursor-pointer` and a
  hover affordance; click (Alpine, client-side only, no server round-trip)
  expands the full message content. Keep newest-first, still last 10.
- **Plan text**: the "Agreed plan" card renders the stored approved-plan text
  (not just the first task key). Reuse the same plan data path the Roadmap
  tab uses. Keep the existing empty-state.

## Hard constraints
- md file is the source of truth for structure; sync is one-way md → DB.
- Sync NEVER writes the live `status` column or creates Executions — only
  `declared_status`, `milestone_id`, `position`, titles.
- Effective status only ever moves a task *up* the tri-state ordering vs md.
- Idempotent: a second sync of an unchanged file writes zero roadmap_events.
- No inline styles in blade; Tailwind classes only (project convention).
- Consensus/plan/milestone toggles are Alpine client-side — NO extra Livewire
  round-trips, NO polling, NO websockets.
- All queries scoped to the project; no N+1 (eager-load tasks on milestones).

## Acceptance (reviewer runs these)
1. `./vendor/bin/pest` — full suite green, no network in tests.
2. RoadmapSyncTest covers: parse of a fixture ROADMAP.md (milestones +
   tasks + summaries + positions); upsert idempotency (2nd sync → 0 events);
   a status change in md → one task_status_changed event; live DB status
   overriding md upward; missing file = no-op; the artisan command runs.
3. ProjectTabsTest extended: 'roadmap' is an allowed tab; unknown tab still
   resets to chat; roadmap tab renders milestones for a project with a fixture md.
4. Grep: no inline `style=` in the new/edited partials.
5. SPEC.md §10 documents the Roadmap tab + ROADMAP.md format + sync rules
   (same commit as the feature).
