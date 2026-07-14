# DISPATCH.md — how Claude runs this project (builder = Qwen via aider)

The operating model for any Claude session driving Majordom development.
Read after CLAUDE.md and agents/RESUME.md.

## Division of labor

- **Claude (the session)** = architect + reviewer + integrator. Writes the
  briefs (`agents/T-NN-*.md`), dispatches the builder, reviews every diff,
  runs ALL acceptance itself, fixes review findings itself (no redispatch
  for small stuff), owns `docs/SPEC.md` (spec updates land in the SAME
  commit as the review fixes they describe).
- **Qwen3.6-27B-Q8_0** (local model, metallama-managed on :8010) = builder.
  Driven headless by aider against one brief at a time.
- The owner (Joz) approves roadmaps, signs off merges to main, wire-tests
  in the browser. Merges to main are `--no-ff`, owner-gated.

## Pre-dispatch checklist

1. Clean tree on the right feature branch (`git status --short` empty).
2. Brief exists in `agents/T-NN-*.md` — self-contained, numbered sections,
   explicit file list, explicit test list, "Hard constraints" section.
3. metallama gateway healthy:
   `curl -s http://127.0.0.1:8010/api/health` → JSON with `llama` binary found.
4. aider binary (see `MAJORDOM_AIDER_BIN` in `.env`):
   `/home/joz/snap/code/249/.local/bin/aider`

## The summon command (run in background, capture the log path)

```bash
OPENAI_API_BASE=http://127.0.0.1:8010/ollama/v1 OPENAI_API_KEY=dummy \
/home/joz/snap/code/249/.local/bin/aider \
  --model openai/Qwen3.6-27B-Q8_0 \
  --yes-always --no-stream --no-check-update --analytics-disable \
  --no-show-model-warnings --no-detect-urls \
  --message-file agents/T-NN-the-brief.md \
  --read <context-file> [--read <context-file> ...] \
  <edit-target-1> <edit-target-2> ...
```

- **Edit targets** = the files the brief says to touch. New-file paths are
  fine — aider creates them empty and fills them.
- **--read** = context files (models/services the task builds on).
  VERIFY each path exists first — a bad path is silently skipped
  (T-36 lesson: `ProviderRegistry` lives at
  `app/Agents/Providers/ProviderRegistry.php`, not `app/Support/`).
- Startup health: watch the task log until `Aider v` / `Model:` appear;
  confirm "whole edit format", the repo-map line, and that every intended
  file was "Added … to the chat".
- aider commits its own work. Exit 0 + clean tree = builder done.
  A run typically produces 1–2 commits (`test:` + `feat:`).

## Review protocol (EVERY task, no exceptions)

1. `git log --oneline` + read the new commits' diffs.
2. `./vendor/bin/pest` — full suite (JSON summary tail). Acceptance for
   every brief = full suite green, no network in tests.
3. Run the brief's acceptance checks YOURSELF (greps for hard constraints,
   `php artisan tinker` wire-tests, browser checks via the owner).
4. Fix findings directly, commit as `review(T-NN): …` — include the
   `docs/SPEC.md` section update in that same commit when the task moved
   an architectural seam.
5. Restart the queue worker after ANY code change:
   `pkill -f "artisan queue:work"`, then background
   `php artisan queue:work --queue=harness,default --tries=1 --timeout=1800`.
   (Reverb on :8815 and `php artisan serve --port=8890` likewise if touched.)
6. Update `agents/RESUME.md` (status block at top) + the session todo list
   AFTER EVERY TASK — assume the session can die at any moment.

## Known Qwen failure modes (check these FIRST at review)

- **Invented framework APIs**: `Http::sequence()->pushFault()` (doesn't
  exist — use a throwing closure in `Http::fake`), `livewire()` Pest helper
  (plugin not installed — this repo uses the `Livewire::test()` facade).
- Enum case-vs-value comparisons; `Process::fake` needing quoted command
  lines; factories skipped in favor of `Model::create` (acceptable, but
  check the states actually exercised).
- Helper/function name collisions across Pest test files (global scope) —
  T-35 lesson.
- Capability ceiling on multi-file refactors: concrete review comments can
  be missed repeatedly (Test Joac case) — that's why briefs carry explicit
  file lists and reviewer-flagged files feed aider fileHints.

## Wire-test snippets

Role → provider resolution (after provider changes):

```bash
php artisan tinker --execute="
\$p = \App\Models\Project::first();
foreach (['architect','reviewer','builder'] as \$r) {
  \$b = app(\App\Support\RoleResolver::class)->resolve(\$r, \$p);
  \$ep = \App\Models\ProviderEndpoint::named(\$b->provider);
  echo \$r.' -> '.\$b->provider.' '.\$b->model.' '.(\$ep?->chatBaseUrl() ?? 'MISSING').PHP_EOL;
}"
```

Expected: architect/reviewer → openrouter `https://openrouter.ai/api/v1`
(key set) · builder → metallama `http://127.0.0.1:8010/ollama/v1` (no key).

## M11 — Project tabs: Overview / Stats (+ contract detail)

- **T-39** — tabs scaffold + Overview + Stats. Brief:
  `agents/T-39-project-tabs-overview.md`. Branch: `feat/m11-project-tabs`.
- **T-40** — "contract detail view" — SCOPE UNCONFIRMED. Ask the owner
  what "contract" means here (provider/runtime contracts docs vs agreed
  consensus specs) BEFORE writing the brief. Do not guess.
