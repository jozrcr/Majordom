# M12 — Autonomy loop (spec)

The product spine: a milestone's tasks chain automatically; milestone
boundaries gate (merge-to-main + start-next) per autonomy profile.

## Confirmed model (owner, 2026-07-16)
- Tasks **always** full auto-chain within a milestone (decompose → build → test
  → review → auto-commit to the milestone branch). No per-task commit click.
- Autonomy profile governs per-task human checkpoints + milestone-boundary:

| Profile | task review/test | milestone boundary | merge-to-main | push |
|---|---|---|---|---|
| attended | ping live | gate (block, ping) | on consent | opt-in |
| overnight | auto-pass | gate (collect in inbox) | on morning consent | opt-in |
| **full_auto** (NEW) | auto-pass | **auto** | **auto** | opt-in |

- Always-gated regardless of profile: reviewer **escalations** (AI stuck).
- full_auto honors the **spend cap** (overnight_spend_cap_usd) — stops + parks
  when exceeded.
- **Push**: per-project opt-in "push after merge" setting (default off). When on
  AND a remote+upstream exist, the gated/auto merge is followed by a push;
  failures surface but never roll back the local merge.

## Current seams (verified)
- `ImplementFeatureWorkflow::startForTask($project,$taskKey,$title,$profile)` —
  creates an execution + runs the chain (delegate→build→test→review→commit_sugg).
- `WorktreeManager::create($task)` — worktree on `branchFor($task)` from `HEAD`.
- `CommitService::apply($suggestion)` — squash-merge task branch → repo checkout
  + commit (currently promotes straight to whatever's checked out).
- Roadmap already materializes all tasks (Milestone hasMany Task, position,
  declared_status) — T-002…T-N exist as rows with titles but **no task.md brief**.
- No decompose exists.

## Task breakdown (dispatch order)

### T-46 — Decompose engine  [core — Claude-written or tight brief + heavy review]
`ArchitectService::decomposeTask(Project, Task): void` — generate the task's
`tasks/<key>/task.md` brief from: roadmap.md (its milestone + the task's
one-line goal), architecture.md, coding_style if present, and a short summary of
already-committed sibling tasks (titles + their handoff/summary). Reuses the
provider/RoleResolver('architect') path. Idempotent-ish: only writes if brief
absent (or force). Emits `task.decomposed`. NO execution started here.
Acceptance: given a project with roadmap.md + architecture.md and a pending task
with only a title, produces a task.md with goal/acceptance/files/test-command.

### T-47 — Milestone integration branches  [WorktreeManager + CommitService]
- `WorktreeManager`: a task's worktree branches from its **milestone branch**
  `majordom/M<n>` (created from HEAD/main on first task of the milestone), not
  straight from HEAD. `branchFor` → `majordom/M<n>/T-<k>` (or keep per-task name
  but base it on the milestone branch).
- `CommitService::apply` (per-task, now un-gated in the loop): squash-merge the
  task branch → its **milestone branch** (+ commit there), NOT main. Reuse the
  robust cleanup + committerEnv fixes.
- New `CommitService::mergeMilestone(Milestone)`: squash/merge `majordom/M<n>` →
  the user's main branch (the gated promotion), + optional push (T-49).
Acceptance: two tasks in a milestone accumulate on `majordom/M<n>`; main is
untouched until mergeMilestone.

### T-48 — Auto-advance + milestone gate + full_auto  [WorkflowEngine/new AdvanceService]
- On a task's commit-to-milestone-branch → resolve next pending task in the SAME
  milestone (by position). If one exists: `decomposeTask` it, then
  `startForTask` (same profile). Auto-chain.
- If the milestone's tasks are all done → **milestone boundary**:
  - attended/overnight → create an Approval `milestone.review` ("M<n> complete
    (N tasks) — merge into main? / start M<n+1>?"). attended pings; overnight
    collects.
  - full_auto → `mergeMilestone` + auto-start first task of M<n+1> (decompose).
- Add `full_auto` to the autonomy profiles (config + Setting). full_auto respects
  the spend cap → park on exceed. Escalations always park regardless.
- Per-task review/test checkpoints stay profile-driven as today (attended block /
  else auto).
Acceptance: attended stops with a milestone approval after the last task;
full_auto rolls into the next milestone; spend-cap parks full_auto.

### T-49 — Push-after-merge opt-in  [Setting + CommitService]
- Per-project Setting `git.push_after_merge` (bool, default false), Settings UI.
- `mergeMilestone` (and any merge-to-main): when enabled AND `git remote` +
  upstream resolve, run `git push`; capture failure into an event/notification,
  do NOT roll back the merge. Uses committerEnv/HOME recovery.
Acceptance: setting off → no push; on with a fake remote → push attempted;
push failure surfaces but merge stands.

### T-50 — UI: milestone gate + relabels + activity  [Livewire/blades]
- Commit card → "Merge into <branch>" framing (per M13 too); within the loop,
  per-task commits are automatic so the card only appears at the milestone gate.
- Milestone gate card: "M<n> complete — Merge into main / Start M<n+1>" with the
  merge + start actions (and reject→rework of the milestone? later).
- Activity: reflect auto-progression (task.decomposed, auto-advance) and label
  by milestone·task.
Acceptance: milestone gate renders + actions work; suite green.

## Notes
- T-46 + T-47 are foundational and independent → can build in parallel.
- T-48 depends on both. T-49 slots after T-47. T-50 after T-48.
- Decompose (T-46) is the highest-value, highest-risk piece (architect prompt +
  context assembly). Consider Claude-written core like T-11.
- Keep everything null-safe on legacy projects (prose roadmaps, no milestone
  branches yet).
