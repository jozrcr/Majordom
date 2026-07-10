# Majordom ↔ metallama — Integration Contract

> metallama owns **all** model runtime. Majordom never spawns a model, never
> touches llama.cpp, never reads a GGUF. It asks metallama over HTTP. This
> document is the contract between them. The boundary is a hard rule
> ([PHILOSOPHY.md](../PHILOSOPHY.md) §4).

## Why a hard boundary

Two processes = two blast radii. metallama runs with `--reload`; editing its
Python kills its child llama-servers. If Majordom lived in-process it would die
too. Separation also lets metallama stay a focused, finished tool and lets
Majordom run headless/remote while metallama sits on the GPU box.

metallama runs on **port 8010** by default (`ustart.sh`). Majordom stores its base
URL in Settings → Integrations (`METALLAMA_BASE_URL`, default
`http://127.0.0.1:8010`).

## What Majordom uses metallama for

1. **Inference (the Builder).** The local Qwen is served through metallama's
   **Ollama/OpenAI-compatible gateway**. Aider (the Builder harness) points at
   this endpoint — see [HARNESS.md](HARNESS.md). Majordom does **not** proxy
   tokens itself; the harness talks to the gateway directly.
2. **Server lifecycle.** Majordom starts/stops managed servers so the right model
   is available for a run, and switches models when a role needs a different one.
3. **State/introspection.** Majordom asks what's running and its health before
   deciding to start/stop (the resource coordinator, below).

## Endpoints (verify against metallama's actual API before wiring)

metallama's routes are documented in its `ARCHITECTURE.md`; **confirm exact
paths/shapes against its code or README during implementation** — treat the
below as the shape, not the literal contract.

| Need | metallama surface (per its ARCHITECTURE.md) |
|---|---|
| List / inspect managed servers | `GET /api/models/*` |
| Start / stop a server | `POST /api/models/*` (start/stop actions) |
| Server status / health | `GET /api/models/{id}/status` |
| Chat inference (Builder) | Gateway: `/ollama/v1/chat/completions` (OpenAI) or `/ollama/api/chat` (Ollama) |
| System / VRAM info | metallama's system routes (for capacity checks) |

Wrap all of this behind **one client class** (`Runtime/Metallama/Client`). No
other part of Majordom issues metallama HTTP calls.

## The resource coordinator

The single component that enforces **one heavy model at a time** (PHILOSOPHY §5)
and never double-spawns Qwen (Q20). Before any Node that needs a model:

1. Ask metallama what's running.
2. If the **required** model for the role is already up and healthy → reuse it.
3. Else if a **different heavy** model is up and capacity won't hold both →
   **stop it, then start** the required one. Serialize; never overlap.
4. If nothing conflicts → start the required one.
5. Emit `ServiceRequested` → `ServiceStarted` / `ServiceStopped` throughout.

Capacity logic is intentionally minimal for v1: this box runs one large Qwen.
The coordinator's job is a state machine (reuse / stop-then-start / start), not a
scheduler. metallama already handles VRAM estimation and AMD/ROCm dual-GPU
specifics — Majordom **does not** re-implement any of that; it may *read*
metallama's VRAM/system info to make the reuse-vs-switch decision, nothing more.

## Failure & resilience

- metallama unreachable → the Runtime node fails cleanly, raises `ApprovalNeeded`
  ("metallama offline"), and the Execution parks. Never crash the app.
- A server that won't start/health-check within a timeout → fail the node with
  the captured metallama error surfaced to the inbox.
- All metallama calls are **contract-tested against a fake client**; live
  wire-tests are guarded and run manually by the reviewer, never in CI
  (CI stays network/GPU/process-free — mirrors metallama's own test discipline).

## What Majordom must NOT do

- Spawn llama.cpp / any model process itself.
- Start a second heavy model concurrently.
- Re-implement VRAM estimation, GGUF parsing, or GPU vendor logic.
- Write to metallama's `config.yaml` or touch its port-8010 instance in tests.
