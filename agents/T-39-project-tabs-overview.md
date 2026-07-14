# T-39 — Project tabs (Chat / Overview / Stats) + Overview page

## Goal
ProjectWorkspace becomes tabbed. Chat/activity stays the default tab and
is NOT modified beyond being wrapped. Add Overview + Stats tabs.

## Edit targets
- app/Livewire/ProjectWorkspace.php
- resources/views/livewire/project-workspace.blade.php
- resources/views/livewire/partials/project-overview.blade.php (new)
- resources/views/livewire/partials/project-stats.blade.php (new)
- tests/Feature/ProjectTabsTest.php (new)

## Read context (--read, paths VERIFIED to exist)
- app/Models/Project.php
- app/Models/ConsensusMessage.php
- app/Models/UsageRecord.php
- app/Models/Execution.php
- app/Models/Approval.php

## Spec
1. `public string $tab = 'chat';` on ProjectWorkspace with `#[Url]`
   (querystring-persisted). Allowed: chat|overview|stats — anything else
   resets to chat in mount().
2. Tab bar at top of project-workspace.blade.php (same button styling as
   settings page tabs). Existing chat markup moves untouched into the
   chat tab branch; Overview/Stats @include the new partials.
3. Overview partial:
   - Project facts: name, status badge, repo_path, test_command,
     workflow name (nullable), last_activity_at.
   - "Agreed plan" card: reuse the exact data path of
     `getPlannedTaskProperty()` (read it first) — render latest approved
     plan; empty-state text when none.
   - Recent consensus: last 10 ConsensusMessage for the project
     (role label + content excerpt, newest first).
4. Stats partial (all queries scoped to the project, single query each,
   no N+1):
   - Totals per role from UsageRecord: prompt_tokens, completion_tokens,
     cost_usd (groupBy role).
   - Grand total cost_usd + token sum.
   - Execution counts by status.
   - Empty-state when no usage yet.
5. NO polling/websocket additions; tabs are plain Livewire state.

## Acceptance (reviewer runs these)
- Livewire::test(ProjectWorkspace, ['project' => $p]) — default tab chat;
  `->set('tab','overview')` renders repo_path; `->set('tab','stats')`
  renders totals seeded via UsageRecord::create (2 roles, assert summed
  cost formatted); invalid `?tab=zzz` falls back to chat.
- Full suite green (`./vendor/bin/pest`), no network in tests.
- Chat tab behavior unchanged (existing workspace tests stay green).
