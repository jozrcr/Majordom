# Majordom — Philosophy & Always-Follow Directions

> This is the north star. When a decision is ambiguous, resolve it *toward* these
> principles. When code or a feature request conflicts with them, the principle
> wins until a human explicitly overrides it. Keep this file short; it is law,
> not documentation.

## The driving question

Everything in Majordom exists to answer one question well:

> **What happens when I select a repo, describe a feature, and let it run?**

If a proposed design makes that flow cleaner, it's probably right. If it doesn't
touch that flow, it's probably premature. Build the flow; let everything else
attach to it.

## The flow (memorize this)

1. **Consensus.** You talk to the **Architect** (a frontier model) until you
   agree on *what* to build. The Architect must surface **every open question
   first** — you answer in-app or via Telegram/Discord — before it proposes a plan.
2. **Plan.** The Architect writes/updates project memory (`architecture.md`,
   `roadmap.md`, …), defines **milestones**, and splits them into **tasks**.
3. **Delegate.** Each task goes to the **Builder** (local Qwen via metallama,
   driven by a harness). The Builder is handed a `role.md` and a `task.md` and
   nothing it doesn't need.
4. **Hand-off.** The Builder implements, writes a hand-off doc, and submits the
   work. You may be pinged to answer a question or intervene.
5. **Review.** The **Reviewer** (frontier) reads the diff and green-lights or
   requests changes. **Automated tests** run as a gate.
6. **Accept.** You are invited to **manually test** — the final acceptance.
7. **Commit.** Commit/push authority stays with **you**. Majordom prepares a
   suggested commit message; it does not push on its own (by default).
8. **Batch.** You can queue features to run unattended overnight and review the
   pile in the morning.

## Principles that always win

1. **Projects are first-class. Models are an implementation detail.**
   The center of the system is `Project → Workflow → Execution → Roles →
   Services → Models`. Never let a UI or data model put "models" at the center.
   The Architect asks for a *role/capability*, never a specific model.

2. **The human is a first-class node, not an afterthought.** Approval, answering
   questions, and manual testing are real workflow steps with real state. The
   degree of autonomy is **configurable** (see *Autonomy profiles* in the spec),
   but the default is: **never commit or push without the human**.

3. **Everything emits events; nothing reaches across the system to act.**
   The workflow engine emits events (`ApprovalNeeded`, `ReviewRequested`, …).
   Notifications, the UI timeline, and logging are *listeners*. The engine must
   not know Telegram exists. Use Laravel's event system — not a message broker.

4. **Majordom never runs models. metallama does.** Majordom asks metallama over
   HTTP to start/stop servers and to serve inference through its gateway.
   Majordom owns *orchestration*; metallama owns *runtime*. Respect the boundary —
   it is why these are two processes (and why editing one never kills the other).

5. **One big local model at a time.** The target machine runs a single large
   Qwen. Majordom must **never** ask metallama to spawn a second heavy model
   concurrently. To switch models: stop, then start. Serialize.

6. **Hardcode adapters. Do not build a plugin system.** You are the only user.
   The harness, the providers, the notifiers are concrete classes behind small
   interfaces. When you need a second one, write a second class. Extract a
   generic system *only if* the concrete ones start to hurt — not before.

7. **Coding-first. Keep the seam, not the feature.** v1 does software
   engineering only. Model a `Service` as fulfilling a `capability` so that a
   future ComfyUI/Whisper node *can* slot in — but write **zero** code for those
   capabilities now. New staff get hired when a real task needs them.

8. **Files are for minds; the database is for machines.** Anything an LLM reads
   or writes as knowledge (architecture, roadmap, decisions, coding style,
   role/task briefs, hand-off notes) lives as **Markdown files** in Majordom's
   per-project store. Everything operational (executions, events, milestones,
   tasks, approvals, suggestions, notifications, config) lives in the **DB**.
   Where both record the same fact (milestones, tasks), the **DB is
   authoritative** — the file is a projection, regenerated on change; hand-edits
   are ingested by the Architect at the next plan pass, never parsed by the engine.

9. **Capture the conversation.** The consensus chat lives *inside* the project so
   the Architect can distill it into memory. Pop-out tools (e.g. Open WebUI) are
   an optional escape hatch, never the system of record.

10. **Fire-and-forget must never break the request path.** Notifications, event
    fan-out, and stats follow metallama's discipline: they never raise into a
    user-facing flow. A failed notification does not fail the workflow.

11. **Build headless-first, wrap later.** The core is a web service — usable in a
    browser, reachable over the LAN (approve from your phone). The NativePHP
    desktop shell is a late, thin wrapper around that same service, never the
    place logic lives.

12. **The docs are part of the code.** A structural change updates the relevant
    spec section in the *same commit*. A spec that drifts from the code is worse
    than no spec. (This is how metallama's ARCHITECTURE.md stays true.)

## How we build Majordom

Two-model workflow, same as metallama: **Claude is the Architect** (writes specs,
reviews, wire-tests); **Qwen is the Builder** (implements precise instructions).
Qwen must not be trusted to self-verify — the reviewer runs every acceptance
test. See [CLAUDE.md](CLAUDE.md).

## Non-goals (v1)

- Non-coding capabilities (image gen, transcription, OCR, documents).
- Parallel workers (one Builder at a time; the engine is *shaped* for parallelism
  via job batching, but v1 runs sequentially).
- A visual node-graph editor (workflows are code/config-defined in v1; the graph
  is a high-value *later* feature).
- Multi-user / teams (single user; auth kept minimal but not precluded).
- A generic plugin marketplace. Ever, probably.
