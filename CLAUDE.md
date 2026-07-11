# CLAUDE.md — working on the Majordom repo

> This file governs how **Claude works on this codebase** while building Majordom.
> For the roles Majordom orchestrates *as a product*, see [AGENTS.md](AGENTS.md).
> For the principles behind every decision, see [PHILOSOPHY.md](PHILOSOPHY.md).

## Read first (in order)

1. [PHILOSOPHY.md](PHILOSOPHY.md) — the law.
2. [docs/SPEC.md](docs/SPEC.md) — the architecture map.
3. [HANDOFF.md](HANDOFF.md) — what to build first and how to verify it.
4. [docs/METALLAMA.md](docs/METALLAMA.md) and [docs/HARNESS.md](docs/HARNESS.md)
   when touching those integrations.

Do not re-derive the design by reading source. These docs are the source of
truth; if the code disagrees with them, that's a bug in one of them — reconcile
in the same commit.

## Your role here

You are the **Architect** of Majordom itself, mirroring metallama's two-model
workflow:

- **Claude (you)** writes specs, breaks work into precise tasks, reviews, and
  **wire-tests** every acceptance criterion.
- **Qwen** implements the precise tasks.
- Qwen executes well but **must not be trusted to self-verify.** You run every
  acceptance test yourself before anything is considered done.

When you delegate a task to Qwen, hand it a scoped brief (goal, files,
acceptance test) — the same discipline the product uses on its own Builder.

## Stack & conventions

- **Laravel + TALL** (Tailwind, Alpine, Livewire). Laravel 13, Livewire 4 components for
  interactive UI; Alpine for local sprinkles; Tailwind for everything visual.
- **Real-time:** Laravel **Reverb** (WebSockets) for the activity timeline and
  live status. Broadcast off domain events — never poll where a broadcast fits.
- **Persistence:** **SQLite** via Eloquent. Migrations for all schema. Keep the
  connection swappable (no SQLite-only SQL) so MariaDB/Postgres remain drop-in.
- **Workflow engine:** Laravel **Queues** (database driver). Each workflow node
  is a **Job**. Use **job batching** where the shape anticipates future
  parallelism, even though v1 runs one Builder at a time.
- **Event bus:** Laravel **Events + Listeners**. Domain transitions dispatch
  events; notifications/UI/logging subscribe. See PHILOSOPHY principle 3.
- **Notifications:** Laravel **Notifications** for outbound (Telegram/Discord).
  Two-way inbound (approve/answer from chat) is a **long-polling daemon** (an
  artisan command looping Telegram `getUpdates`), not a notification channel —
  map inbound messages to approval/answer actions. No public webhook exposure
  by default; a webhook controller is the alternative for tunnel users.
- **Providers (Architect/Reviewer):** call provider REST APIs through Laravel's
  **Http** client behind a small `Provider` interface. DeepSeek and the local
  Qwen gateway are OpenAI-compatible; Anthropic uses its Messages API shape.
  When implementing the Anthropic client, consult the **claude-api** reference
  for current model ids and request shape — do not hardcode from memory.

## Hard rules (from PHILOSOPHY — repeated because they're easy to violate)

- **Never manage models directly.** All model lifecycle and inference go through
  the metallama client (see docs/METALLAMA.md). No `subprocess`, no llama.cpp.
- **Never spawn a second heavy model** concurrently. Switch = stop then start,
  serialized through the resource coordinator.
- **Never auto-commit or auto-push** the *user's* target repo without the
  configured human gate. (Aider's own auto-commit on the feature branch is
  fine as a WIP mechanism — the *promotion* to the user's history is gated.)
- **Keep the harness behind its interface** (docs/HARNESS.md). Aider is one
  implementation; nothing outside the adapter may know Aider's flags.
- **Fire-and-forget never raises** into a request/job path (notifications,
  events, stats). Follow metallama's daemon-thread/queue discipline.
- **No plugin system, no premature capability code.** Coding-only v1.

## Testing discipline

- Every feature ships with tests. Use an **isolated database and config** for
  tests — never point tests at a real Majordom instance, a real target repo, or a
  running metallama. Fake the metallama client and the harness at their
  interfaces.
- Harness and metallama integrations get **contract tests** against a fake plus,
  where practical, one guarded live wire-test the reviewer runs manually.
- Livewire components: test render + the key interactions. Keep the suite fast,
  deterministic, and free of network/GPU/process dependencies.

## Doc maintenance

A structural change (new domain entity, new node type, new event, changed
integration contract) updates the relevant section of `docs/SPEC.md` (or
METALLAMA/HARNESS) **in the same commit**. Treat the spec like a test that must
stay green.

## Branches / process

- `main` is the release line. Feature work on `feat/*`, merged `--no-ff`.
- Agent working docs (task briefs, review notes) live in `agents/` — gitignored,
  mirroring metallama.
- Commit trailer: a `Co-Authored-By` line naming the Claude model that did the
  work (e.g. `Co-Authored-By: Claude Fable 5 <noreply@anthropic.com>`).
