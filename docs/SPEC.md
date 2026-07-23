# Majordom — Architecture Specification

> Version 0.1 · 2026-07-09 · the map. Read [PHILOSOPHY.md](../PHILOSOPHY.md)
> first. This document defines the domain model, the workflow lifecycle, the
> event system, the module layout, and where data lives. Integration surfaces
> have their own contracts: [METALLAMA.md](METALLAMA.md), [HARNESS.md](HARNESS.md).

## 1. Overview & scope

Majordom is a Laravel application that orchestrates AI agents to perform software
engineering on git repositories, with the human in the loop. It coordinates:

- a **frontier model** (Architect/Reviewer) it talks to over provider HTTP APIs,
- a **local model** (Builder) it drives through a coding-agent **harness**,
- **metallama**, which owns all model runtime (start/stop/serve), reached over HTTP.

**v1 does coding only.** The design keeps a seam for future capabilities but
writes no code for them (PHILOSOPHY §7). Everything below is coding-scoped.

## 2. Domain model

The spine: **Project → Execution → Milestone → Task → (Node runs)**. Roles bind
to Services; Services front Models. Events record everything.

| Entity | Responsibility | Storage |
|---|---|---|
| **Project** | A registered git repo + its config overrides + a pointer to its memory dir. The unit everything hangs off. | DB row; memory as files |
| **Workflow** | A named template: an ordered chain of steps, each `{type, role, config}` — the node type, the actor (a Role name resolved per project), and per-step tunables (e.g. `rescue_role` on review steps). Legacy plain-string chains normalize on read (`ChainStep`). Builtins seeded; custom chains editable in Settings. | DB (chain JSON) |
| **Execution** | One run of a Workflow against a Project (an "implement this feature" instance). Holds status, autonomy profile, current node, frontier-spend counter + budget cap. | DB |
| **Milestone** | A chunk of the roadmap the Architect defined. Belongs to a Project; realized within Executions. | DB (+ `roadmap.md`) |
| **Task** | The atomic unit the Builder executes. Has a `role.md` + versioned `task.md` briefs, a feature branch (checked out in its own worktree), a status, and an `implementation_strategy` (Builder Selection, M14b — which class of Builder implements it; null = local). | DB (+ files) |
| **Capability** | Opt-in actor rights per project (M14b, `projects.capability_level`): the Architect's repository access — `none` / `read` (default) / `commands` (gated on a sandbox). Read is enforced in-process (realpath confinement + tracked-only); commands would need OS sandboxing, so it is UI-gated. Set in the Project Settings tab; consumed at `ArchitectService::converse` (reads loop + system prompt). See `App\Enums\CapabilityLevel`. | DB |
| **Node / Step** | A unit of the workflow (control / AI / dev / runtime / human). Records inputs, outputs, timing. | DB |
| **Role** | A responsibility (Architect/Builder/Reviewer) bound to a Service. The **Reviewer is the Architect** by default (M16-D, one mind): with no explicit binding the reviewer role resolves to the resolved Architect model; a distinct reviewer is opt-in (`MAJORDOM_REVIEWER_MODEL` / a `reviewer` Role row). | DB / config |
| **Service** | A concrete endpoint fulfilling a role/`capability` (frontier API, or a metallama target). | DB / config |
| **Event** | An immutable record of a transition; the bus payload and the timeline source. | DB |
| **Approval / Gate** | A pending human decision (approve / answer / test). Feeds the "Needs You" inbox. | DB |
| **CommitSuggestion** | A prepared commit message + the diff it covers. Never auto-applied. | DB |
| **Notification** | An outbound message + its delivery state; and the mapping for inbound replies. | DB |
| **Artifact** | Anything produced: diffs, hand-off docs, test output, generated files. | Files (+ DB pointer) |

Relationships: a Project has many Executions; an Execution traverses many Nodes
and produces/advances Milestones and Tasks; a Task runs the Build→Test→Review
loop; every transition emits an Event; human-blocking transitions create an
Approval.

## 3. Workflow lifecycle — *Implement Feature*

This is the Q8 vision made concrete. Each numbered phase is one or more Nodes.
Gates marked **[gate]** consult the autonomy profile (§8) to decide *block &
notify* vs *auto-proceed & collect*.

1. **Consensus** *(AI: Architect + Human)*
   - Architect ingests the intent + repo context + project memory.
   - Architect emits **every open question**; `QuestionRaised` per question.
   - **[gate]** Human answers (app / Telegram); `QuestionAnswered`. Loop until
     the Architect has none left → `ConsensusReached`.
2. **Plan** *(AI: Architect)*
   - Writes/updates `architecture.md`, `roadmap.md`, `decisions.md`,
     `coding_style.md`, `current_iteration.md`. Emits `PlanDrafted`,
     `MilestonesDefined`.
   - **[gate]** Human approves the plan (optional per profile).
3. **Decompose** *(Delegator + Architect)*
   - Architect splits the active milestone into Tasks; writes each
     `tasks/<id>/role.md` + `task.md`. Emits `TasksDefined`.
4. **Prepare runtime** *(Runtime)*
   - Resource coordinator ensures the Builder's model is up in metallama,
     stopping any conflicting heavy model first (§ METALLAMA). Emits
     `ServiceRequested` → `ServiceStarted` (or reuse). Creates the feature
     branch in a **dedicated git worktree** (`Git` node) — the Builder never
     operates in the user's checkout, so the user's working copy stays
     untouched even mid-overnight-run. Emits nothing model-heavy in parallel.
5. **Build** *(AI: Builder via harness)* — per Task, sequentially in v1
   - **Greenfield bootstrap (M14a/T-67, reconciled M14b):** on an empty repo the
     Architect selects the **frontier Builder** to scaffold (layout, manifests,
     README, test config) via a dedicated flow (direct file generation — no repo
     yet for an aider worktree). Role separation holds: the scaffold goes through
     the **Reviewer** before commit (one corrective retry, else surfaced — never
     commits a rejected scaffold). No human gate (owner-locked). Then decompose
     runs on real ground. `ArchitectService::bootstrapRepo`.
   - **Builder Selection (M14b):** the Architect *selects* a Builder per task
     rather than executing itself. A Task carries `implementation_strategy`
     (`local` default → local Qwen; `frontier` → a frontier model bound as
     `frontier_builder`). `BuildNode` routes on it — the node is model-agnostic,
     so this is a pure routing decision; whatever builds still flows execute →
     test → **review** (role separation: a frontier Builder never reviews its
     own output). `ImplementationStrategy::builderRole()` maps strategy → role;
     `BuilderSelector::assign()` is the single write seam (Architect decompose,
     escalation "select stronger Builder", owner UI) and emits
     `task.builder_selected`; `BuildNode` emits `build.builder_selected` naming
     the actual actor. This is the proactive generalization of the reactive
     *Frontier rescue* below (§7). `hybrid`/`auto` (telemetry-driven) reserved.
   - Harness runs Aider(Qwen) in the task's worktree with `role.md`+`task.md`.
     Emits `TaskDelegated` → `BuilderStarted` → `BuilderProgress*`.
   - Builder may escalate a blocking question → **[gate]** Human intervenes.
   - Builder writes `handoff.md`. Emits `BuilderCompleted` (or `BuilderFailed`),
     `HandoffWritten`.
6. **Test** *(Dev: Tests)*
   - Runs the Task's/Project's test command. Emits `TestsStarted` →
     `TestsPassed` / `TestsFailed`. On fail, route back to Build with the failure
     as the next task revision (bounded retry budget).
7. **Checkpoint & auto-advance** *(within a milestone)*
   - Per-task review is gone (M15). Once a task is test-green its work is
     already committed to the shared milestone branch `majordom/<key>`; the
     `confirm_commits` checkpoint accepts it (task → Approved, worktree detached)
     and `TaskChain::advance` decomposes + starts the next task in the same
     milestone. No per-task human gate, no promotion to main here.
8. **Milestone review** *(AI: Architect-as-Reviewer, at the boundary)*
   - When a milestone's tasks are all done (`milestone.tasks_complete`), the
     Architect (as Reviewer — one mind, §Role) judges the milestone's
     **cumulative** diff at the right altitude: `base_commit..HEAD` on
     `majordom/<key>`, where `base_commit` is the first task's recorded fork
     point (falls back to `main...HEAD`). Read via `MilestoneDiff` — the single
     definition of "the milestone's work", shared by the review and the gate.
   - Tool-driven (`MilestoneReviewService`): `read_diff`/`read_file` ground the
     judgment, then exactly one of `approve_milestone` (with a `how_to_test`) /
     `request_changes` / `ask_owner`. `request_changes` → **one** keyed fix-task
     whose acceptance criteria are the findings; it rebuilds and re-reviews,
     bounded by `MAX_REVIEW_ROUNDS` (2) then escalates instead of looping. An
     empty diff fast-approves.
9. **Merge gate** *(Human: review surface + promotion)*
   - Approve / escalate / stuck all raise the **[gate]** `MilestoneMerge`
     approval — never a dead end. The gate is a real review surface (M16-A): its
     payload carries a frozen `recap` — milestone goal, each task with its
     acceptance criteria, the `git diff --numstat` diffstat, the reviewer's
     verdict, the `how_to_test`, and the `branch`/`worktree` — assembled once by
     `MilestoneRecap` so the decision never depends on a worktree that may later
     be merged away. The gate surfaces that `worktree` path with an **Open in
     VS Code** action (M16-C) so the disposable worktree is legible — the target
     dir is derived server-side (gate worktree, else `repo_path`), launched via
     the array-form `Process` under `majordom.editor.command`; best-effort, it
     never raises into the request (`editor.opened`).
   - **Three resolutions** (no silent decline): **merge now** promotes the
     branch; **not yet** DEFERS (`ApprovalStatus::Deferred`) — branch and
     worktree kept intact, out of the inbox but re-openable via
     `deferredMilestoneGates` and merged later (`mergeDeferredMilestoneGate`);
     **request changes** (a reason is required) routes to the Architect as ONE
     keyed fix-task (`TaskChain::requestChangesFromOwner`) that rebuilds and
     re-reviews — the owner's words are never dropped.
   - Granting promotes the branch: `CommitService::mergeMilestone` runs
     `git merge --no-ff majordom/<key>` into the project's `repo_path`, removes
     the milestone worktree, optionally pushes, then `startNextMilestone`. It
     reports the resulting HEAD (`head`, `into_branch` on `milestone.merged`) so
     the owner can see where the work landed. Under **full_auto** the merge is
     automatic on approval; every other profile waits. **Default: no push
     without the human.**
10. **Close** — `current_iteration.md` and `roadmap.md` updated; next milestone
    or `WorkflowCompleted`.

**Batch / overnight:** an Execution (or several, queued) may run under the
*Overnight* profile — phases 1–8 auto-proceed where non-destructive, piling every
human-needed item into the morning inbox; phase 9 (the merge gate) **always**
waits (only full_auto promotes to main without you).

## 4. Node types (v1, coding-scoped)

- **Control:** Start, End, Condition, Loop, Wait. *(Parallel deferred — §
  PHILOSOPHY non-goals; the engine uses job batching so it can be added.)*
- **AI:** Architect (consensus/plan/decompose/review), Builder (implement).
- **Dev:** Git (branch/diff/commit-prep), Tests (run suite), Shell (guarded),
  File Ops.
- **Runtime:** Start Service / Stop Service (via metallama).
- **Human:** `human_task` (the chain hands *you* a step: node opens an Approval
  carrying the worktree path, notifies via Telegram/UI with Done / Skip, and
  the engine parks until you grant), `human_review` (a human gate over the most
  recent completed build/human_task diff: grant advances the chain, reject with
  a comment becomes the revision brief and re-arms the build). Both are
  first-class chain step types usable anywhere in a custom Workflow.

Each node is a Queue **Job** with typed input/output persisted on its Node row,
and it emits its lifecycle Events.

**Long-running nodes:** Build can run for tens of minutes. Those Jobs go on a
dedicated queue with a generous `timeout`/`retry_after` (the framework defaults
would re-dispatch or kill a run mid-flight). The Job spawns the harness as a
tracked `Process` and persists its PID on the Node row, so a killed worker
(deploy, crash) leaves a *detectable* orphan that gets failed cleanly — never a
zombie still editing the repo.

## 5. Event catalog (the bus)

Events are Laravel events, persisted as `Event` rows and broadcast via Reverb.
Listeners: the timeline projector, the notifier, the logger, the inbox
projector. The engine dispatches; it never calls a listener directly.

```
ProjectCreated
WorkflowStarted / WorkflowPaused / WorkflowResumed / WorkflowCompleted / WorkflowFailed
ConsensusStarted · QuestionRaised · QuestionAnswered · ConsensusReached
PlanDrafted · MilestonesDefined · TasksDefined
ServiceRequested · ServiceStarted · ServiceStopped
TaskDelegated · BuilderStarted · BuilderProgress · BuilderCompleted · BuilderFailed · HandoffWritten
TestsStarted · TestsPassed · TestsFailed
ReviewRequested · ReviewApproved · ReviewChangesRequested
ApprovalNeeded · ApprovalGranted · ApprovalRejected
HumanTestRequested · HumanTestPassed · HumanTestRejected
CommitSuggested · Committed · Pushed
NotificationSent
```

`ApprovalNeeded` is the keystone: every human gate raises it, one listener turns
it into an inbox item, another into an outbound message. Two-way replies resolve it via
`ApprovalGranted`/`ApprovalRejected`/`QuestionAnswered`.

## 6. Module architecture (how it maps to Laravel)

FastAPI-style "modules" become Laravel namespaces/service classes — not a
separate framework. Keep boundaries clean even though it's one app.

```
app/
  Core/
    Workflow/      workflow definitions, the engine, node dispatch (Jobs)
    Events/        domain events + the bus wiring
    Autonomy/      profiles + gate resolution (block vs collect)
  Runtime/
    Metallama/     the HTTP client + resource coordinator  (docs/METALLAMA.md)
  Agents/
    Harness/       the Harness interface + AiderHarness     (docs/HARNESS.md)
    Providers/     Provider interface + OpenAI-compatible client + ProviderRegistry
                   (resolves role → provider_endpoints row → configured client)
    Architect/     prompts + consensus/plan/decompose/review orchestration
    Builder/       task-brief assembly + harness invocation
    Reviewer/      diff-review orchestration
  Projects/
    Memory/        the Markdown project-memory store (read/write .md files)
    Repositories/  git operations (branch/diff/commit-prep) via Process
  Integrations/
    Telegram/      outbound notifier + inbound long-poll daemon → actions
    Discord/       outbound notifier
  Support/         shared: config resolution (global → project override), etc.
Http/              Livewire components + controllers = the API/UI layer
```

Laravel primitives do the heavy lifting: **Queues** = the engine's execution
substrate; **Events/Listeners** = the bus; **Notifications** = the messengers;
**Reverb** = live UI; **Eloquent/SQLite** = state.

## 7. Data & disk layout

**Database (SQLite):** projects, workflows, executions, nodes, milestones,
tasks, events, approvals, commit_suggestions, notifications, services, roles,
settings, consensus_messages, questions (the consensus chat + its
ask-all-questions gate are operational state — the distilled transcript in
memory files is the projection). Migrations own the schema. No SQLite-specific
SQL (stay portable).

**Files — per-project memory store** (Majordom's data dir, e.g.
`~/.majordom/projects/<slug>/`), *not* the user's repo by default:

```
<slug>/
  architecture.md      roadmap.md      decisions.md
  coding_style.md      current_iteration.md
  consensus/<exec>.md          # distilled consensus transcript
  tasks/<id>/role.md
  tasks/<id>/task.md           # versioned per revise round: task.v2.md, …
  tasks/<id>/handoff.md
  artifacts/<...>              # diffs, test logs
```

Rationale (PHILOSOPHY §8): LLMs read/write these directly and you can edit them
by hand. DB rows point at file paths + hold metadata. **Opt-in sync:** selected
docs (typically `architecture.md`, `coding_style.md`) can be copied into the
user's repo (e.g. `.majordom/` or `.ai/`) and committed *with* the code — off by
default to keep the repo clean.

**Authority rule:** where the DB and a memory file record the same facts
(milestones, tasks), the **DB is authoritative**; `roadmap.md` is a projection
regenerated on change. Hand-edits to memory files are ingested by the Architect
at the next consensus/plan pass — the engine never parses prose to decide flow.

**Logs:** executions and node runs log to files (per execution) plus event
rows. Not a priority feature (metallama already logs model I/O), but retained
for debugging (Q30).

## 8. Autonomy profiles

A per-run (defaultable per-project) setting that decides, for each **[gate]**,
whether Majordom **blocks & pings** or **auto-proceeds & collects**.

| Profile | Consensus Qs | Plan approval | Review arbitration | Manual test | Commit / Push |
|---|---|---|---|---|---|
| **Attended** | block & ping | block | block | block | **block (always)** |
| **Overnight** | block & ping* | auto | auto (approve) | collect | **block (always)** |

\* Consensus questions always block — Majordom cannot invent your intent. Under
*Overnight*, if the Architect still has questions, that Execution parks in the
inbox rather than guessing. Everything else non-destructive flows; the morning
inbox holds the plan, the diffs, the review verdicts, and the manual-test
invitations. **Commit and push never auto-run** (PHILOSOPHY §2). Overnight runs
also carry a **frontier-spend cap** per Execution; exceeding it parks the
Execution in the inbox like any other gate.

**Per-role spend caps + full_auto local fallback (M14b, `SpendGuard`):** the flat
per-Execution cap is a blunt total — the real cost lever is the frontier Builder,
while the Reviewer (a cheap model) is inconsequential. `workflow.role_spend_caps.<role>`
sets a per-role ceiling (frontier_builder capped by default; reviewer/architect/
builder uncapped). When a frontier build's budget is gone it **downgrades to the
local Builder** (free) via `SpendGuard::mustBuildLocal`. Under **full_auto** the
flat cap no longer hard-parks (owner policy: keep moving) — builds go local and
the cheap Reviewer keeps running; **attended/overnight still park** on the flat
cap. Emits `build.builder_downgraded`.

Profiles are data, not code — new profiles can be added without touching the
engine (the engine only asks "is this gate blocking under the active profile?").

## 9. UI philosophy & screens

Modern, dark-first, calm. A subtle command-console aesthetic — monospace
accents, status LEDs, restrained motion — **not** a gamer-RGB dashboard. Visibly
distinct from metallama. Tailwind design tokens; Livewire for interactivity;
Reverb for live updates.

- **Home — Project dashboard.** Cards per project: name, active milestone, a
  status light (`idle` / `working` / **`needs you`**), last activity.
- **Project workspace** — tabbed (M11); the `tab` state is querystring-persisted
  (`#[Url]`) and normalized to `chat` on any unknown value:
  - **Chat** (default) — the four core regions:
    1. **Consensus chat** — the primary surface; talk to the Architect, answer its
       questions, approve the plan. The captured system-of-record for intent. The
       **same** free-chat surface persists after a plan is approved (M16): steering
       is not a separate mode — a plain reply just continues the conversation, and
       asking for a scope change lets the Architect re-`propose_plan`. That revision
       is human-gated exactly like the first plan; approving it preserves built work
       (RoadmapSync upserts by key), resets the loop, regenerates the restart
       brief (`plan.redefined`), and reconciles worktrees — a milestone the revised
       roadmap no longer declares (`RoadmapSync::milestoneKeysIn`) has its orphaned
       `majordom/<key>` worktree and branch removed (`WorktreeManager::reconcileMilestones`,
       best-effort, `worktrees.reconciled`) so no stale worktree shadows the active
       one. No one-shot redefine turn — it is the tool loop.
    2. **Roadmap** — milestones → tasks, editable, reflecting `roadmap.md`.
    3. **Activity timeline** — the live event feed (Reverb): delegated → building →
       review → …
    4. **Review surface** — appears at a gate: inline **diff viewer** with
       approve / reject / comment, plus an "open in VS Code" deep-link escape hatch
       (Q31).
  - **Overview** — read-only project facts (status, repo, test command, workflow,
    last activity), the agreed plan (first task from `getPlannedTaskProperty`), and
    recent consensus messages.
  - **Stats** — usage totals per role (tokens + `cost_usd`) from `UsageRecord`, a
    grand total, and execution counts by status. A richer per-project cost
    dashboard (dataviz) is a later enhancement.
  - **Settings** (T-55) — project-scoped controls: rename (slug immutable — it
    keys memory/worktrees), archive/unarchive, autonomy profile (canonical
    `switchProfile` surface), `confirm_commits` per-task diff checkpoint,
    `push_after_merge` (mirrors the **global** `Setting::get('git.push_after_merge')`
    — labeled "(global)"; per-project column deliberately NOT added, owner call
    pending), and a disabled `night_mode` placeholder for M14/T-62.
  - A slim **common header** (name · repo path · status badge, null-safe) renders
    above the tab bar on EVERY tab; chat-only extras (Architect chip, workflow
    select) stay inside the chat tab.
- **"Needs You" inbox** — global across all projects; the queue of open
  Approvals/Questions/Test-invites. **This is exactly what Telegram/Discord
  mirror** — one queue, three windows (app, phone, desktop). Backbone of the
  overnight→morning review.
- **Settings** — global defaults with per-project overrides (mirrors metallama's
  preset→override pattern):
  - **Actors/Roles** — bind Architect/Builder/Reviewer to a model+endpoint+harness
    +role.md; swap models per role here.
  - **Services/Models** — registered endpoints (frontier providers, metallama targets).
  - **Workflow** — template + params: autonomy profile, which gates block, test
    command, branch naming, commit-message style.
  - **Integrations** — Telegram/Discord tokens & routing, metallama base URL.

## 10. Configuration model

Two layers: **global defaults** and **per-project overrides**. Resolution order:
`project override > global default > built-in default`. Stored in DB (`settings`,
`provider_endpoints`, `roles`) with a thin resolver in `Support/`. This is where
"swap the model/source for a role" lives (Q16), and it's why roles are indirection
over services rather than hardcoded model names.

**Provider endpoints** (M10): every LLM target is a `provider_endpoints` row —
`name`, `driver` (`openai_compatible` | `metallama`), `base_url`, encrypted
`api_key`, `timeout`, `meta`. Roles reference endpoints by name;
`ProviderRegistry` turns a `RoleBinding` into a configured client, and
`BuildNode` only engages the metallama `ResourceCoordinator` when the resolved
endpoint's driver is `metallama` (frontier roles bypass it entirely). Built-in
rows (openrouter, metallama) do **not** freeze their secrets/URLs at migration
time: they carry `meta.api_key_config` / `meta.base_url_config` pointers so the
live value keeps tracking config/env; custom rows store literal values and the
column wins. Rule: any change to provider resolution updates this section in
the same commit.

**Role developer view** (M10/T-37): each role card carries optional advanced
knobs in `roles.meta` (no dedicated columns): `system_prompt_extra` (thinker
roles — appended as a trailing paragraph to the built-in system prompt, never
replacing it: the structured-JSON output contracts live in the built-in part),
`extra_instructions` (builder-type roles — appended to the task message as an
`## Owner role instructions` block, since aider owns its own system prompt),
plus `top_p`, `frequency_penalty`, `presence_penalty`, `stop` (≤4 strings),
`timeout`. Absent key = today's behavior: the UI omits keys on blank input,
`ProviderRequest` carries them as nullables appended after the original
params, and `OpenAiCompatibleProvider` includes them in the body only when
non-null (`timeout` falls back to the endpoint's). Numeric values are cast on
read (`(float)`/`(int)`) because the JSON column round-trip may demote e.g.
`-1.0` to `-1`.

### 10.1 Roadmap Tab & Sync Rules
The **Roadmap** tab renders **DB entities** (Milestone → Task) as a 3-level accordion — milestone (status dot + colour) → task (status + colour) → task detail (description/goal) — NOT raw markdown. The markdown (`roadmap.md`) is only an INPUT to `RoadmapSync`; the screen only ever reads DB rows.
- **Source precedence:** `RoadmapSync` reads `{$repo_path}/agents/ROADMAP.md` first. If absent, it falls back to project memory `roadmap.md`. If neither yields content, it is a no-op.
- **Format:** Tolerates both structured `## M<N> — <title>` and legacy prose `## Milestone <N>: <title>`. Keys normalize to `M<N>`. Tasks come from `- [<mark>] <T-NN> — <title>` checkboxes. Non-checkbox lines accumulate into milestone `summary`.
- **Sync:** Upserts `milestones` and `tasks` into the DB. Idempotent; emits `roadmap_events` only on real deltas (status/title/position changes). Description sync from `tasks/<key>/task.md` in memory is silent (no event).
- **Effective Status:** UI displays tri-state status derived from `max(db_status, declared_md_status)` on `todo < ongoing < done`. Sync writes `declared_status`/`milestone_id`/`position`/`title`/`description` — never the live `status` column (harness-owned).
- **Milestone status** is *derived*, never stored: all tasks done → `done`; all tasks todo → `todo`; any other mix → `ongoing`.
- **UI:** 3-level Alpine.js accordions, Tailwind styling. No inline styles. No raw markdown rendering.

### 10.2 Exchange Trace
A condensed, per-execution view of hand-offs between actors (architect → builder → reviewer → …). Derived as a **projection over the existing `events` table** (+ `Task.description` for instruction content + `UsageRecord` for per-role tokens/cost). No new logging pipeline, no log parsing, no LLM summaries.
- **Projection:** `ExchangeTrace::for(Execution)` walks ordered events and maps them to typed exchanges (`instruction`, `result`, `failure`, `verdict`, `rework`, `clarification`, `consensus`, `commit`).
- **Mapping:** Strict event-name-to-exchange table. `task.delegated`/`delegate.started` emits the instruction exactly once per execution. `build.completed`/`failed`, `test.completed`, `review.completed`/`retry`/`failed`, `human_review.waiting_human`, `consensus.message`, `question.answered`, `commit.applied` map to their respective kinds.
- **Content:** `excerpt` is the first ~200 chars of `full` (single-lined). `full` is assembled from event payloads or `Task.description`. Null-safe everywhere.
- **Usage:** `ExchangeTrace::usageFor(Execution)` groups `UsageRecord` by role for a compact header strip.
- **UI:** Rendered in the `exchanges` tab. Execution picker, usage strip, vertical card list with Alpine accordions for full text. Tailwind only, no inline styles.

### 10.3 Milestone Metrics
A per-milestone (and per-task) projection of delivery metrics, surfaced in the **Stats** tab. Derived entirely from existing `UsageRecord`, `Event`, `Task`, and `Execution` rows — no new logging or LLM calls.
- **Projection:** `MilestoneMetrics::forMilestone(Milestone)` and `::forTask(Task)` compute aggregates scoped to the execution IDs linked to the milestone's tasks.
- **Metrics:** `tokens` (prompt+completion summed per architect/builder/reviewer), `cost_usd`, `human_interventions` (count of `approval.granted`, `question.answered`, `human_review.waiting_human`, `human_task.waiting_human`), `rework_cycles` (count of `review.retry`/`review.failed`, maxed against `task.revision - 1`), `files_changed` (distinct union of `build.completed` payload files), `time_to_completion` (span of events in seconds), `tests_added` (deferred, always null).
- **UI:** Rendered in the Stats tab as an accordion per milestone with a compact metric grid. Tasks drill down client-side. Null-safe on sparse/never-run tasks.

## 11. Non-goals / deferred (do not build in v1)

Non-coding capabilities · parallel Builders · visual node-graph editor ·
multi-user · generic plugin system · NativePHP desktop shell (later; build the
web service first — PHILOSOPHY §11). Each is a *later hire into the household*, not a
v1 concern. Keep seams (a `capability` on Service, batching in the engine, a
web-first core) — write no feature code for them.
