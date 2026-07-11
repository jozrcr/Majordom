# Majordom — Build Handoff

> **You are the Claude instance bootstrapping this repo.** This is your
> pass-over. You start cold; these docs are everything. Read
> [PHILOSOPHY.md](PHILOSOPHY.md) → [docs/SPEC.md](docs/SPEC.md) →
> [AGENTS.md](AGENTS.md) → [CLAUDE.md](CLAUDE.md) → the two integration contracts,
> then start at "Milestone 0" below. Build in thin vertical slices; prove the
> riskiest thing first.

## Context you won't otherwise have

- **Sibling project:** metallama (running checkout: `../metafork-work/metallama-stable`;
  its `ARCHITECTURE.md` lives in `../metallama`) — a working FastAPI + vanilla-JS
  llama.cpp manager. Read its `ARCHITECTURE.md`. Majordom **depends on it** as the
  model runtime and calls it over HTTP (see [docs/METALLAMA.md](docs/METALLAMA.md)).
  Do not modify metallama from here.
- **The owner is a TALL-stack expert** (Tailwind/Alpine/Laravel/Livewire). The
  stack was chosen to play to that strength. Write idiomatic Laravel.
- **How we work:** Claude is Architect (specs, reviews, wire-tests); Qwen is
  Builder. Qwen must never self-verify — you run every acceptance test. Task
  briefs live in `agents/` (gitignored).

## The riskiest assumption — de-risk it first

> *Can Aider drive the local Qwen (served by metallama) fully headless on a real
> repo and return a usable diff + machine-readable result?*

Everything else is standard Laravel. **This** is the load-bearing unknown. The
first real slice must prove it end-to-end with a button in a browser before any
polish. If Aider can't do headless-with-structured-output well against a local
endpoint, we swap the Harness impl *now*, while it's cheap — the
[Harness interface](docs/HARNESS.md) exists exactly so that swap costs nothing
downstream. Fallback order: **OpenCode first** (open source, drives any
OpenAI-compatible endpoint — preserves the independence goal);
Claude-Code-headless last (it ties the Builder to Anthropic).

## Repo scaffold

```
majordom/                     (this repo — new, sibling to metallama)
  composer.json              Laravel 13, Livewire 4, Reverb, notification channels
  app/                       (namespaces per docs/SPEC.md §6)
    Core/{Workflow,Events,Autonomy}
    Runtime/Metallama
    Agents/{Harness,Providers,Architect,Builder,Reviewer}
    Projects/{Memory,Repositories}
    Integrations/{Telegram,Discord}
    Support/
  database/migrations/       SQLite schema (portable SQL only)
  resources/views/           Livewire + Tailwind
  routes/                    web; inbound Telegram is a console long-poll command
  tests/                     Pest/PHPUnit; fakes for Harness + Metallama client
  agents/                    (gitignored) task briefs, review notes
  docs/                      SPEC / METALLAMA / HARNESS (moved with this handoff)
  PHILOSOPHY.md CLAUDE.md AGENTS.md README.md HANDOFF.md
```

Keep the doc set at the root as-is; they are the spec of record.

## Milestones (thin vertical slices)

Each milestone is shippable and independently verifiable. Do them in order.

**M0 — Skeleton.** Laravel app, SQLite, queue (database driver), Reverb wired,
a static-token auth middleware (the app is LAN-exposed from day one), Livewire
dashboard shell, a `Project` model (register a git repo path). Home screen
lists projects with a status light.
*Verify:* create a project pointing at a throwaway git repo; it appears on the
dashboard; queue + Reverb boot clean; unauthenticated requests are rejected.

**M1 — Runtime + Harness (the de-risk).** `Runtime/Metallama/Client` (list /
start / stop / status against a **fake**, plus a guarded live test) + resource
coordinator. `Agents/Harness` interface + `AiderHarness`. A single dev-only
button: "run this trivial task on the repo" → coordinator ensures Qwen is up in
metallama → Aider runs headless → return a `HarnessResult` → show the diff.
*Verify (reviewer, manual live):* on a scratch repo, a real task ("add a
docstring to X") produces a real diff and a `status: completed`, fully
non-interactive. **This is the go/no-go on Aider.**

**M2 — Consensus + Plan.** `Providers` (Anthropic + OpenAI-compatible) behind the
interface. Architect consensus chat (Livewire) with the **ask-all-questions**
mandate → `QuestionRaised`/`QuestionAnswered` gate → `ConsensusReached` →
Architect writes `architecture.md` + `roadmap.md` + one `task.md` into the
project memory store.
*Verify:* a consensus session that refuses to plan until questions are answered,
then writes coherent memory files to `~/.majordom/projects/<slug>/`.

**M3 — The full task loop.** Wire M1+M2 into the *Implement Feature* workflow for
**one** task: Delegate → Build (Aider/Qwen) → `handoff.md` → Tests node →
Reviewer verdict → inline diff review → human approve → `CommitSuggestion` (no
push).
*Verify:* end-to-end on a real repo, one task goes from brief to a reviewed,
tested, commit-ready diff — with the human gate blocking commit.

**M4 — Event bus + timeline + inbox.** Formalize the [event catalog](docs/SPEC.md#5-event-catalog-the-bus);
timeline projector + Reverb live feed; the global "Needs You" inbox.
*Verify:* every phase transition shows on the timeline live; gates land in the inbox.

**M5 — Two-way Telegram.** Outbound messages via Notifications; an inbound
**long-polling daemon** (artisan `getUpdates` loop — no public webhook) mapping
replies to `ApprovalGranted`/`QuestionAnswered`. Answer a
consensus question and approve a review **from your phone**.
*Verify:* a gate raised in-app resolves from Telegram and the Execution advances.

**M6 — Autonomy profiles + batch.** Attended vs Overnight gate resolution;
queue several Executions overnight; morning inbox holds the pile; commit/push
still blocks.
*Verify:* an Overnight run auto-proceeds through non-destructive gates and parks
everything needing you, never committing.

**Later (not now):** visual node-graph editor · NativePHP desktop shell · more
capabilities (the next hires into the household). Keep the seams; write no code.

## Standing constraints (do not drift)

- Never manage models directly; always through the metallama client. Never spawn
  two heavy models. (docs/METALLAMA.md)
- Never auto-commit/push the user's repo without the human gate.
- The Builder operates in a **git worktree** on the feature branch — never the
  user's checkout.
- Keep Aider behind `Harness`; keep providers behind `Provider`.
- Fire-and-forget (notifications/events/logs) never raises into a request/job.
- CI stays network/GPU/process-free; live integration tests are guarded + manual.
- Update the relevant `docs/` section in the same commit as any structural change.

## Start-here checklist

1. Read the doc set in the order at the top of this file.
2. Confirm metallama runs locally and note its base URL + the Qwen model name.
3. Scaffold M0; get the dashboard + queue + Reverb green.
4. Do **M1** and get the reviewer a live Aider+Qwen wire-test result. Do not
   proceed past M1 until the de-risk passes.
5. Proceed M2 → M6, each as a reviewed vertical slice.
