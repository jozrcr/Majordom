<title>Majordom — Cahier des Charges</title>

# MAJORDOM

## Cahier des Charges

A personal AI orchestration platform for software engineering.
Loop with a frontier model to agree on *what* to build, delegate it to a local
model, review and test it — and stay the commander at every gate.

| VERSION | STATUS | SCOPE | DATE |
|---|---|---|---|
| **0.2** | **Spec & handoff** | **Coding v1** | **2026-07-10** |

*Internal working document — written for me, not for a client. Synthesises the
Majordom handoff pack (PHILOSOPHY, SPEC, AGENTS, METALLAMA, HARNESS, HANDOFF).
v0.2: renamed from Maester; folds in the pre-build design decisions (worktrees,
long-polling, DB authority, spend caps, versioned briefs, M0 auth, harness
fallback order).*

---

## 01 · RÉSUMÉ — At a glance

Majordom is a personal AI **orchestration platform** focused on software
engineering. You bring a git repository and an intent ("add GitHub OAuth
login"); Majordom runs the workflow that turns it into **reviewed, tested,
commit-ready** changes, with configurable human gates throughout. It never runs
models itself — model runtime is delegated to **metallama** over HTTP, and
coding agents run behind a pluggable **harness**. The name fits: a majordom is
the *chief steward of an estate* — it coordinates the *staff* (the agent
roles), keeps the *ledgers* (project memory), carries the *messages*
(notifications), and never signs in the owner's name (commit authority stays
with you). Every future capability is a *new member of staff* brought into the
household.

| Field | Value |
|---|---|
| **Name** | Majordom |
| **Type** | Personal AI orchestration platform (software engineering) |
| **Owner** | Single user — you |
| **Status** | Specification & handoff — no code yet |
| **Core stack** | Laravel · TALL (Tailwind/Alpine/Livewire) · Reverb · SQLite · Queues · Aider |
| **Runtime dependency** | metallama — a separate service, reached over HTTP |
| **Scope (v1)** | Coding workflows only; image/audio/OCR capabilities deferred |
| **Desktop** | Web-first (browser, LAN-reachable, token-gated); NativePHP shell much later |
| **Platform** | Linux; AMD/ROCm dual-GPU handled entirely by metallama |
| **Build process** | Two-model workflow — Claude specs & reviews, Qwen builds |

## 02 · DIRECTION — Vision & goals

The whole system exists to answer one question well:

> **"What happens when I select a repo, describe a feature, and let it run?"**

- **Automate the software-engineering loop** with configurable human control —
  from intent to reviewed, tested changes.
- **Projects are first-class; models are an implementation detail.** The
  Architect asks for a role/capability, never a specific model.
- **Keep the human in command.** Approvals, answering questions, and manual
  testing are real workflow steps — and Majordom never commits or pushes
  without you.
- **Reuse metallama for runtime; don't rebuild it.** Majordom owns
  orchestration; metallama owns the models.
- **Coding first, capabilities later.** The architecture leaves a seam (image
  gen, transcription…) but writes zero code for it now.

## 03 · LIFECYCLE — The core flow: *Implement Feature*

Each phase is one or more workflow nodes. Gates marked **[gate]** consult the
run's autonomy profile to decide *block & notify* vs *auto-proceed & collect*.

| # | Phase | What happens |
|---|---|---|
| 1 | **Consensus** | Talk to the **Architect** (frontier) until you agree. It must surface **every open question first** — answered in-app or via Telegram/Discord — before proposing a plan. **[gate]** |
| 2 | **Plan** | Architect writes project memory (architecture / roadmap / decisions / coding-style) and defines milestones. **[gate]** optional plan approval. |
| 3 | **Decompose** | Architect splits the milestone into tasks; writes each *role.md* + *task.md* brief. |
| 4 | **Prepare runtime** | Resource coordinator ensures the right model is up in metallama (stopping any conflicting heavy model first); the feature branch is checked out in a **dedicated git worktree** — your working copy is never touched. |
| 5 | **Build** | The **Builder** (local Qwen via Aider) implements one scoped task in the worktree, then writes a *handoff.md*. May escalate a blocking question. **[gate]** |
| 6 | **Test** | Automated test node runs the suite. On failure, loop back to Build with the failure as the **next task revision** (bounded retries). |
| 7 | **Review** | The **Reviewer** (frontier) reads the diff + criteria + style; approves or requests changes — comments become the next task revision (*task.v2.md*, …) → back to Build. **[gate]** |
| 8 | **Accept** | You are invited to **manually test** the feature — the final acceptance. **[gate]** |
| 9 | **Commit** | Majordom prepares a suggested commit message + diff. **You** commit / push. **Default: never without you. [gate]** |
| 10 | **Close** | *current_iteration.md* and *roadmap.md* updated; next milestone, or the workflow completes. |

**Batch / overnight:** queued runs use the *Overnight* profile — phases 1–8
auto-proceed where non-destructive and pile everything needing you into the
morning inbox; commit/push (phase 9) always waits. Each Execution also carries
a **frontier-spend cap** — exceeding it parks the run in the inbox like any
other gate.

## 04 · PRINCIPLES — Directions that always win

- **Projects first, models last.** Never let a UI or data model put models at
  the center.
- **The human is a first-class node.** Autonomy is configurable; the default is
  never commit/push without you.
- **Everything emits events; nothing reaches across to act.** The engine emits
  (`ApprovalNeeded`…); notifications, timeline and logging just listen. The
  engine must not know Telegram exists.
- **Majordom never runs models — metallama does.** Respect the HTTP boundary;
  it's why they're two processes.
- **One big local model at a time.** Never spawn a second heavy model; to
  switch, stop then start.
- **Hardcode adapters; no plugin system.** One user. Concrete classes behind
  small interfaces; generalise only when it hurts.
- **Files for minds, the database for machines.** LLM-facing knowledge is
  Markdown; operational state is DB. Where both record the same fact
  (milestones, tasks), the **DB is authoritative** — files are regenerated
  projections; hand-edits are ingested by the Architect at the next plan pass.
- **Build headless-first, wrap later.** The core is a web service; the desktop
  shell is a thin late wrapper.
- **The docs are part of the code.** A structural change updates the spec in
  the same commit.

## 05 · TECHNOLOGY — Stack & rationale

| Layer | Choice | Why |
|---|---|---|
| App framework | Laravel | Expert-level velocity; first-party batteries for every core need |
| UI | TALL — Tailwind / Alpine / Livewire | Reactive UI without a separate SPA |
| Real-time | Laravel Reverb (WebSockets) | Live activity timeline & status |
| Workflow engine | Queues (database driver) | Durable node execution; long-run queue for Build nodes (tracked PID, generous timeouts); batching-ready for future parallelism |
| Event bus | Events + Listeners | Decoupled notifications / UI / logging |
| Notifications | Notifications + **long-polling** | Two-way Telegram / Discord (approve & answer from your phone) — inbound via an artisan `getUpdates` daemon; **no public webhook exposure** |
| Persistence | SQLite | Zero-config, portable, bundles into NativePHP; swappable to MariaDB/PG |
| Builder harness | Aider (headless, CLI `--message`) | Proven scripted coding against a local OpenAI-compatible endpoint; shelled out, never imported |
| Frontier access | Provider HTTP | Anthropic Messages / OpenAI-compatible; direct API, not a file harness |
| Access control | Static-token middleware (from M0) | The app is LAN-exposed from day one |
| Desktop (later) | NativePHP | Single-file desktop app from the same codebase — much later |

**Why Laravel — and the tripwire.**
~70% of Majordom is a web app with real-time UI, background jobs, an event bus,
notifications and settings — Laravel's exact sweet spot. The AI-heavy work is
delegated *out of the host language* (metallama for runtime, Aider for the
agent loop, plain HTTPS for frontier calls), so the core never needs Python's
AI ecosystem. Expertise is a multiplier on a genuine technical fit, not a
substitute for one.

> **Tripwire:** if Majordom ever grows its *own* in-process agent loops (rather
> than delegating to Aider), the fix is a Python *agents* sidecar service (like
> metallama), not a stack switch. The architecture (services over HTTP) makes
> that escape hatch free.

## 06 · ARCHITECTURE — Domain model & modules

The spine: **Project → Execution → Milestone → Task → Node runs**. Roles bind
to Services; Services front Models; Events record every transition.

| Entity | Responsibility |
|---|---|
| Project | A registered git repo + config overrides + a pointer to its memory dir |
| Workflow | A code/config-defined template (ordered nodes + gates). v1 ships *Implement Feature* |
| Execution | One run of a workflow on a project; holds status, autonomy profile, current node, frontier-spend counter + budget cap |
| Milestone / Task | Roadmap chunk / the atomic unit the Builder executes (role.md + versioned task.md, own worktree) |
| Node | A workflow unit (control / AI / dev / runtime / human) run as a queue job |
| Role / Service | A responsibility (Architect/Builder/Reviewer) bound to a concrete endpoint/capability |
| Event | Immutable transition record — the bus payload and the timeline source |
| Approval | A pending human decision (approve / answer / test) — feeds the "Needs You" inbox |

**Modules (Laravel namespaces, one app):**

- **Core** — Workflow engine & node dispatch, Events (the bus), Autonomy
  (profiles & gate resolution)
- **Runtime/Metallama** — the HTTP client + resource coordinator
- **Agents** — Harness (Aider) · Providers (Anthropic / OpenAI-compatible) ·
  Architect / Builder / Reviewer orchestration
- **Projects** — Memory (the Markdown store) · Repositories (git ops incl.
  worktrees, via Process)
- **Integrations** — Telegram (outbound + inbound long-poll daemon) · Discord
- **Http** — Livewire components + controllers = the API/UI layer

**Long-running nodes:** Build can run for tens of minutes — those jobs live on
a dedicated queue with generous timeouts; the harness runs as a tracked process
(PID persisted) so a killed worker leaves a detectable orphan, never a zombie
still editing the repo.

**Event bus keystone:** `ApprovalNeeded` — every human gate raises it; one
listener turns it into an inbox item, another into an outbound message; two-way
replies resolve it. The engine dispatches; it never calls a listener directly.

## 07 · ACTORS — Agent roles

| Role | Who (v1) | Harness | Edits files? |
|---|---|---|---|
| **Architect** | Frontier — Claude (quality) / DeepSeek (budget) | Direct provider API | No — plans & writes memory |
| **Builder** | Local Qwen via metallama | Aider (headless) | **Yes** — the only editor |
| **Reviewer** | Frontier (same pool) | Direct provider API | No — reads diff, judges |
| **Delegator** | Majordom engine (+ Architect for the cut) | — | No — routes work |
| **Human (you)** | App / Telegram / Discord | — | You decide |

The Builder receives **only** a *role.md* (who it is + rules) and a *task.md*
(one scoped goal + acceptance test) — enough to work, nothing it doesn't need.
It never self-certifies: "tests pass" is asserted by the Tests node.

## 08 · FEATURES — What it does

| Feature | Description |
|---|---|
| Project dashboard | Cards per project with a status light: idle / working / **needs you**. |
| Consensus chat | The primary surface — talk to the Architect, answer its questions, approve the plan. Captured as the system of record. |
| Roadmap panel | Milestones → tasks, editable, reflecting roadmap.md (DB-authoritative). |
| Activity timeline | Live event feed (Reverb): delegated → building → review → … |
| Inline diff review | Approve / reject / comment on the diff in-app, with an "open in VS Code" escape hatch. |
| "Needs You" inbox | One global queue of open approvals/questions/test-invites — mirrored to Telegram/Discord. |
| Two-way notifications | Outbound messages + inbound replies (long-polling): answer a question or approve a review from your phone. |
| Worktree isolation | The Builder edits an isolated checkout; your working copy is never touched. |
| Settings | Actors/roles, services/models, workflow params, integrations — global defaults + per-project overrides. |
| Autonomy profiles | Attended vs Overnight gate behaviour, per run — plus a per-run frontier-spend cap. |
| Project memory store | Per-project Markdown knowledge the agents read & write; you can edit by hand. |
| Resource coordinator | Serialises model usage through metallama; never double-spawns the heavy model. |

## 09 · CONTROL — Autonomy profiles

Per-run (defaultable per-project). Decides, for each gate, whether Majordom
**blocks & pings** or **auto-proceeds & collects**.

| Gate | Attended | Overnight |
|---|---|---|
| Consensus questions | block & ping | block & ping* |
| Plan approval | block | auto |
| Review arbitration | block | auto (approve) |
| Manual test | block | collect for morning |
| Frontier-spend cap | block on breach | park on breach |
| **Commit / Push** | **block (always)** | **block (always)** |

\* Consensus questions always block — Majordom cannot invent your intent; an
Overnight run with open questions parks in the inbox rather than guessing.
Commit and push never auto-run.

## 10 · BOUNDARIES — Integrations & data

**metallama (HTTP):** list / start / stop / status of managed servers +
inference through its OpenAI-compatible gateway. Majordom may stop a server to
switch models but never spawns llama.cpp, never re-implements VRAM/GGUF/GPU
logic, never runs two heavy models at once.

**Harness & providers:** Aider (the only file-editor) points at the local Qwen,
driven via its non-interactive CLI in a git worktree; Architect/Reviewer use
direct provider HTTP. Both sit behind small interfaces. If Aider fails the M1
go/no-go, the fallback order is fixed: **OpenCode first** (open source, any
OpenAI-compatible endpoint — preserves the independence goal);
Claude-Code-headless last (ties the Builder to Anthropic).

**Storage split:** Markdown files = LLM-facing knowledge (architecture,
roadmap, decisions, coding-style, role/task briefs, hand-offs) in a per-project
store (`~/.majordom/projects/<slug>/`), not the user's repo by default (opt-in
sync). Everything operational — executions, events, milestones, tasks,
approvals, suggestions, config — lives in SQLite. Where both record the same
fact, **the DB is authoritative**; files are regenerated projections.

## 11 · DELIVERY — Roadmap: thin vertical slices

| Milestone | Deliverable | Verified by |
|---|---|---|
| M0 | Laravel skeleton, SQLite, queue, Reverb, static-token auth, dashboard, Project model | Project appears; queue+Reverb boot clean; unauthenticated requests rejected |
| **M1 ◆** | metallama client + resource coordinator; Harness + AiderHarness | **Go/no-go:** real Aider+Qwen diff, fully headless |
| M2 | Providers; consensus chat with ask-all-questions; Architect writes memory | Refuses to plan until questions answered |
| M3 | Full one-task loop: Build → Test → Review → approve → commit suggestion | End-to-end reviewed, tested, commit-ready diff |
| M4 | Event catalog, live timeline, "Needs You" inbox | Transitions stream live; gates land in inbox |
| M5 | Two-way Telegram (outbound + inbound **long-polling**) | Approve / answer from the phone; run advances |
| M6 | Autonomy profiles + overnight batch | Overnight auto-proceeds, parks gates, never commits |
| Later | Visual node-graph editor · NativePHP shell · new capabilities (new hires) | — |

◆ **M1 is a hard gate:** prove Aider can drive the local Qwen headless and
return a structured diff **before** building anything on top. If it can't, swap
the harness impl while it's cheap (OpenCode first).

## 12 · GUARDRAILS — Non-goals & how it's built

**Deliberately deferred (write no v1 code):**

- Non-coding capabilities (image gen, transcription, OCR, documents)
- Parallel Builders (engine is batching-shaped, but v1 runs sequentially)
- Visual node-graph editor (workflows are code/config-defined in v1)
- Multi-user / teams · a generic plugin system · the NativePHP desktop shell

**How Majordom is built:**

Two-model workflow, mirroring metallama: **Claude is the Architect** (specs,
reviews, wire-tests everything); **Qwen is the Builder** (implements precise
tasks and is never trusted to self-verify). A fresh Claude bootstraps a new
repo (sibling to metallama) from the handoff pack, building in the vertical
slices above and stopping for sign-off at each milestone. Docs are maintained
as code — a structural change updates the spec in the same commit.

---

*Majordom — Cahier des Charges · v0.2 · 2026-07-10 · internal working document.
Source of truth: the handoff pack (PHILOSOPHY, SPEC, AGENTS, METALLAMA,
HARNESS, HANDOFF).*
