# Majordom

> Your AI chief-of-staff for software engineering. Majordom loops with a frontier
> model to reach consensus on *what* to build, delegates the work to a local
> model, reviews it, and keeps **you** — the commander — in the loop the whole way.

Majordom is a personal AI **orchestration platform**. You bring a git repository
and an intent ("add GitHub OAuth login"); Majordom runs the workflow that turns
that into reviewed, tested, commit-ready changes — with configurable human
gates at every step. It does **not** run models itself: it delegates model
lifecycle to [metallama](../metallama) over HTTP and drives coding agents
through a pluggable harness.

## The name

A majordom is the **chief steward of an estate**: it runs the household on the
owner's behalf, **coordinates the staff** (the agent roles), keeps the
**ledgers** (project memory), and **carries the messages** (notifications) —
but never signs in the owner's name (commit authority stays with you). Every
future capability Majordom gains (image generation, transcription, …) is a
**new member of staff** brought into the household.

## Status

**Specification & handoff stage.** No code yet. This repository currently holds
the blueprint the first implementation is built against. Read in this order:

1. **[PHILOSOPHY.md](PHILOSOPHY.md)** — the north star. Principles that always win.
2. **[docs/SPEC.md](docs/SPEC.md)** — the architecture: domain model, lifecycle, events, modules, data.
3. **[AGENTS.md](AGENTS.md)** — the product's agent roles (Architect / Builder / Reviewer).
4. **[docs/METALLAMA.md](docs/METALLAMA.md)** — the integration contract with metallama.
5. **[docs/HARNESS.md](docs/HARNESS.md)** — the coding-agent harness adapter interface.
6. **[CLAUDE.md](CLAUDE.md)** — how Claude should work *on this repo* while building it.
7. **[HANDOFF.md](HANDOFF.md)** — start here to build: scaffold, milestones, verification.

## Stack (decided)

Laravel + TALL (Tailwind / Alpine / Livewire) · Laravel Reverb (real-time) ·
SQLite · Queues (workflow engine) · Events (internal bus) · Notifications
(Telegram/Discord) · Aider (first harness) · NativePHP (desktop, much later).

## Scope

**v1 is coding-only** — the software-engineering workflow on git repos. Other
capabilities (ComfyUI, Whisper, OCR) are *deliberately deferred*; the
architecture leaves a seam for them but does not build for them. See
[PHILOSOPHY.md](PHILOSOPHY.md) § Non-goals.
