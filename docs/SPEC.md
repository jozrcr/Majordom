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
| **Workflow** | A named, code/config-defined template (ordered nodes + gates). v1 ships one: *Implement Feature*. | Code + DB reference |
| **Execution** | One run of a Workflow against a Project (an "implement this feature" instance). Holds status, autonomy profile, current node, frontier-spend counter + budget cap. | DB |
| **Milestone** | A chunk of the roadmap the Architect defined. Belongs to a Project; realized within Executions. | DB (+ `roadmap.md`) |
| **Task** | The atomic unit the Builder executes. Has a `role.md` + versioned `task.md` briefs, a feature branch (checked out in its own worktree), a status. | DB (+ files) |
| **Node / Step** | A unit of the workflow (control / AI / dev / runtime / human). Records inputs, outputs, timing. | DB |
| **Role** | A responsibility (Architect/Builder/Reviewer) bound to a Service. | DB / config |
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
   - Harness runs Aider(Qwen) in the task's worktree with `role.md`+`task.md`.
     Emits `TaskDelegated` → `BuilderStarted` → `BuilderProgress*`.
   - Builder may escalate a blocking question → **[gate]** Human intervenes.
   - Builder writes `handoff.md`. Emits `BuilderCompleted` (or `BuilderFailed`),
     `HandoffWritten`.
6. **Test** *(Dev: Tests)*
   - Runs the Task's/Project's test command. Emits `TestsStarted` →
     `TestsPassed` / `TestsFailed`. On fail, route back to Build with the failure
     as the next task revision (bounded retry budget).
7. **Review** *(AI: Reviewer + Human)*
   - Reviewer reads diff + criteria + style + handoff + test result. Emits
     `ReviewRequested` → `ReviewApproved` / `ReviewChangesRequested`. On changes,
     comments become the next task revision (`task.v2.md`, …) → back to Build
     (bounded loop). *(M3 status: the revision brief is written and the
     execution parks for the owner to restart; the automated back-to-Build
     re-arm ships with the M4 event bus. Same for test failures in phase 6.)*
   - **[gate]** Human may be asked to arbitrate or approve the review.
8. **Accept** *(Human: Manual test)* — configurable, on by default
   - **[gate]** Human is invited to test the feature. `HumanTestRequested` →
     `HumanTestPassed` / rejected (→ back to Build with notes).
9. **Commit** *(Dev: Git + Human)*
   - Majordom prepares a `CommitSuggestion` (message + diff). Emits
     `CommitSuggested`. **[gate]** Human commits; optionally pushes.
     `Committed` / `Pushed`. **Default: no push without the human.**
10. **Close** — `current_iteration.md` and `roadmap.md` updated; next milestone
    or `WorkflowCompleted`.

**Batch / overnight:** an Execution (or several, queued) may run under the
*Overnight* profile — phases 1–8 auto-proceed where non-destructive, piling every
human-needed item into the morning inbox; phase 9 (commit/push) **always** waits.

## 4. Node types (v1, coding-scoped)

- **Control:** Start, End, Condition, Loop, Wait. *(Parallel deferred — §
  PHILOSOPHY non-goals; the engine uses job batching so it can be added.)*
- **AI:** Architect (consensus/plan/decompose/review), Builder (implement).
- **Dev:** Git (branch/diff/commit-prep), Tests (run suite), Shell (guarded),
  File Ops.
- **Runtime:** Start Service / Stop Service (via metallama).
- **Human:** Approval, Answer-Questions, Manual-Test.

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
    Providers/     Provider interface + Anthropic / OpenAI-compatible clients
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

Profiles are data, not code — new profiles can be added without touching the
engine (the engine only asks "is this gate blocking under the active profile?").

## 9. UI philosophy & screens

Modern, dark-first, calm. A subtle command-console aesthetic — monospace
accents, status LEDs, restrained motion — **not** a gamer-RGB dashboard. Visibly
distinct from metallama. Tailwind design tokens; Livewire for interactivity;
Reverb for live updates.

- **Home — Project dashboard.** Cards per project: name, active milestone, a
  status light (`idle` / `working` / **`needs you`**), last activity.
- **Project workspace** — four regions:
  1. **Consensus chat** — the primary surface; talk to the Architect, answer its
     questions, approve the plan. The captured system-of-record for intent.
  2. **Roadmap** — milestones → tasks, editable, reflecting `roadmap.md`.
  3. **Activity timeline** — the live event feed (Reverb): delegated → building →
     review → …
  4. **Review surface** — appears at a gate: inline **diff viewer** with
     approve / reject / comment, plus an "open in VS Code" deep-link escape hatch
     (Q31).
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
`services`, `roles`) with a thin resolver in `Support/`. This is where "swap the
model/source for a role" lives (Q16), and it's why roles are indirection over
services rather than hardcoded model names.

## 11. Non-goals / deferred (do not build in v1)

Non-coding capabilities · parallel Builders · visual node-graph editor ·
multi-user · generic plugin system · NativePHP desktop shell (later; build the
web service first — PHILOSOPHY §11). Each is a *later hire into the household*, not a
v1 concern. Keep seams (a `capability` on Service, batching in the engine, a
web-first core) — write no feature code for them.
