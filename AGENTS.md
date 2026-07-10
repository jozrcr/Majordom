# Majordom — Agent Roles (the product's actors)

> This describes the **roles Majordom orchestrates as a product** — not how Claude
> works on this repo (that's [CLAUDE.md](CLAUDE.md)). A *role* is a
> responsibility; a *service* is the concrete model/endpoint bound to it. Roles
> are stable; the model behind a role is swappable at runtime.

## The cast

| Role | Who runs it (v1 default) | Harness | Edits files? |
|---|---|---|---|
| **Architect** | Frontier — Claude Opus (quality) / DeepSeek (budget) | Direct provider API | No — plans & writes memory |
| **Builder** | Local Qwen via metallama gateway | **Aider** (headless) | **Yes** — the only file-editor |
| **Reviewer** | Frontier (same pool as Architect) | Direct provider API | No — reads diff, judges |
| **Delegator** | Majordom engine (+ Architect for decomposition) | n/a | No — routes work |
| **Human (you)** | — | The app / Telegram / Discord | You decide |

The model bound to each role is configured in **Settings → Actors** (global
default) and may be overridden per project. Swapping a role's model is an
endpoint change, nothing more — this is why the frontier roles use a direct
provider client and the Builder points Aider at metallama's OpenAI-compatible
gateway.

---

## Architect

**Purpose:** reach consensus with the human on *what* to build, then produce the
plan and project memory.

**The consensus mandate (non-negotiable):** before proposing any plan, the
Architect must **enumerate every open question** and get answers. Its system
prompt requires it to ask, not assume. Questions are surfaced through the app's
consensus chat and mirrored to Telegram/Discord; answers flow back through the
same "Needs You" channel. Only once questions are resolved does it propose
milestones.

**Inputs:** the human's intent; the target repo's existing context (its
`README`, `CLAUDE.md`, docs — Majordom feeds these in); current project memory.

**Outputs (written to project memory as Markdown):**
- `architecture.md` — the target repo's architecture as the Architect understands it.
- `roadmap.md` — milestones, ordered.
- `decisions.md` — decisions + rationale (append-only log).
- `coding_style.md` — conventions the Builder must follow (seeded from the repo).
- `current_iteration.md` — what's in flight now.
- `tasks/<id>/role.md` + `tasks/<id>/task.md` — one scoped brief per task (below).

**Also acts as:** the **Delegator's brain** — it decomposes a milestone into
tasks and writes each task brief. The engine does the dispatching; the Architect
decides the cut.

## Builder

**Purpose:** implement exactly one scoped task on the repo. Nothing more.

**Contract — the Builder receives only two documents:**
- `role.md` — who it is and the rules it always follows (persona, coding_style
  digest, "don't touch X", test/commit conventions). Stable across tasks.
- `task.md` — the single task: goal, acceptance criteria, files likely involved,
  the test command that must pass. Scoped so the Builder needs no broader context.

This is deliberate (cf. *Severance*): a worker refining one focused parcel does
**not** get the whole picture — it gets a clean, complete brief. Under-briefing
causes drift; over-briefing wastes local context window.

**Execution:** the engine runs the Builder through the **Aider** harness in a
git worktree on the feature branch (never the user's checkout), pointed at the
local Qwen served by metallama. Aider edits,
runs the task's test command, and iterates toward green.

**Outputs:**
- The diff (uncommitted or WIP-committed on the feature branch — see spec).
- `tasks/<id>/handoff.md` — what it did, what it couldn't, open questions,
  how it verified. This is what the Reviewer and the human read first.

**The Builder never self-certifies.** "Tests pass" is asserted by the **Tests
node**, not by the Builder's say-so.

## Reviewer

**Purpose:** judge the Builder's work.

**Inputs:** the diff, `task.md` (acceptance criteria), `coding_style.md`, the
Builder's `handoff.md`, and the Tests node result.

**Outputs:** a verdict — `approved` or `changes_requested` — with structured
comments. On `changes_requested`, the comments become the next task revision
(`task.v2.md`, …) for the Builder (the review→revise loop). Same frontier pool as the Architect; may be a
different model than the Architect if configured.

## Delegator

Not an LLM by itself — it's the **engine function** that takes the Architect's
task cut and drives each task through the Build → Test → Review → (revise) loop,
emitting events at every transition, respecting the active **autonomy profile**,
and serializing model usage through the metallama resource coordinator.

## Human (you)

A first-class actor with real workflow state. You appear as:
- **Consensus partner** — answer the Architect's questions; approve the plan.
- **Interventions** — answer mid-build questions the Builder/Reviewer escalate.
- **Approver** — approve/reject at configured gates (in-app or from Telegram).
- **Final tester** — manual acceptance after automated tests pass.
- **Commit authority** — you commit/push; Majordom only suggests.

Which of these are *blocking* vs *auto-proceed-and-collect* depends on the
autonomy profile of the run (Attended vs Overnight). See the spec.
