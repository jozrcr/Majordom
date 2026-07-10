# Majordom — Harness & Provider Adapters

> The **Harness** is how Majordom makes an autonomous coding agent edit a repo.
> Aider is the first (and v1-only) implementation, driving the local Qwen served
> by metallama. Frontier roles (Architect/Reviewer) do **not** use a
> file-editing harness — they use a **Provider** client. Both live behind small
> interfaces so nothing else in the app knows the concrete tool
> ([PHILOSOPHY.md](../PHILOSOPHY.md) §6).

## Two distinct mechanisms — don't conflate them

| | **Harness** (Builder) | **Provider** (Architect / Reviewer) |
|---|---|---|
| Job | Autonomously edit files, run tests, iterate | Converse / plan / judge a diff |
| Model | Local Qwen via metallama gateway | Frontier (Claude / DeepSeek) |
| Concrete impl (v1) | **Aider**, headless | Anthropic Messages / OpenAI-compatible HTTP |
| Touches the repo? | **Yes** | No |
| Output | A diff + structured result | Text / structured JSON |

The Builder is the only thing that writes to the user's repo. Keeping the
frontier roles as plain API calls makes them simpler, cheaper to control, and
machine-readable by construction.

## The Harness interface

A minimal contract the engine depends on; `AiderHarness` implements it. Shape
(PHP, illustrative):

```php
interface Harness
{
    // Run one scoped task to completion, non-interactively.
    public function runTask(HarnessRequest $req): HarnessResult;
}

// HarnessRequest carries:
//   repoPath        the task's git worktree (isolated checkout of the
//                   feature branch — never the user's working copy)
//   modelEndpoint   metallama gateway base URL + model name
//   rolePrompt      contents of role.md
//   taskPrompt      contents of task.md
//   testCommand     the command that must pass (may be null)
//   fileHints       optional list of files to focus on
//   limits          max iterations / wall-clock / token budget

// HarnessResult carries (all machine-readable):
//   status          completed | failed | needs_human
//   diff            unified diff of what changed
//   filesChanged    list
//   testsPassed     bool | null (null = not run here)
//   summary         short natural-language recap
//   openQuestions   escalations for the human, if any
//   rawLog          captured harness output (for the log file)
```

**Non-negotiable requirements for any Harness implementation:**

- **Headless / non-interactive.** No TTY prompts. Overnight batch depends on this.
- **Machine-readable result.** The engine routes on `status`/`testsPassed`, shows
  `diff`, and files `summary`/`handoff` — it must not scrape human prose to
  decide flow.
- **Repo-scoped & branch-aware.** Operates on the given worktree/branch; does
  not push; does not touch other repos or the user's checkout.
- **Bounded.** Honors iteration/time/token limits and returns `needs_human`
  rather than looping forever.

## AiderHarness (the v1 implementation)

Aider is chosen because it is the most proven tool for **scripted,
non-interactive** coding against a **local OpenAI-compatible endpoint**, it is
git-native (clean diffs/commits), and it can run a test command and iterate
toward green.

Invocation notes (**verify exact flags/API against Aider's current docs when
implementing — do not trust these verbatim**):

- Drive Aider through its **non-interactive CLI** (`--message` mode) and shell
  out via `Process` — its Python API is explicitly unsupported upstream; do not
  import it. Never screen-scrape an interactive session.
- Point it at metallama's gateway as an **OpenAI-compatible** base URL + the Qwen
  model name (from the resolved Service). No cloud keys for the Builder.
- Feed `role.md` + `task.md` as the system/instruction context; pass `fileHints`
  as the edit scope.
- Use Aider's **test-command** integration so it runs and self-fixes; capture the
  result into `testsPassed` + `rawLog`.
- **Auto-commit:** Aider committing WIP on the *feature branch* is fine and
  useful as the diff mechanism. Promotion into the user's real history stays
  gated by the human Commit node — Aider must never push.

`AiderHarness` is the **only** class that knows Aider exists. Swapping later =
a new `Harness` implementation, no engine changes. Fallback order if Aider
fails the M1 go/no-go: **OpenCode first** (open source, drives any
OpenAI-compatible endpoint); Claude-Code-headless last (ties the Builder to
Anthropic).

## The Provider interface (Architect / Reviewer)

```php
interface Provider
{
    public function chat(ProviderRequest $req): ProviderResponse; // may stream to DB/UI
}
```

- **OpenAI-compatible** implementation covers DeepSeek *and* any local model
  served through metallama's gateway (shared shape).
- **Anthropic** implementation uses the Messages API shape. When building it,
  consult the **claude-api** reference for current model ids and request/response
  format — do not hardcode from memory.
- Streaming: providers may stream, but Majordom streams **into the DB/timeline via
  events**, not by holding a long-lived HTTP response open (PHP is poor at that;
  the queue+event model sidesteps it — PHILOSOPHY §11 rationale). Concretely:
  buffer chunks in memory, append to the message row + broadcast over Reverb
  every N tokens/ms, persist the final message once — never an Event row per
  chunk.
- Role→Provider→model binding is resolved from config (Settings → Actors), giving
  the runtime model-swap per role.

## Testing

- `Harness` and `Provider` are faked at their interfaces for unit/feature tests.
- One guarded, manual **live wire-test** per integration (real Aider+Qwen on a
  throwaway repo; real provider call) that the reviewer runs by hand — never in
  CI. CI stays free of network/GPU/process.
