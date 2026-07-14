# Session resume — state as of 2026-07-16 (T-38 MERGED to main; next: M11)

> Fresh session: read CLAUDE.md → this file → **agents/DISPATCH.md** (how to
> summon the Qwen builder via aider + the review protocol). Persistent memory
> (auto-loaded) has environment quirks; this file is the project state.
> RULE: update the status block below after EVERY task — assume the session
> can die at any moment (token budget).

## Status block (update after every task)

- Branch: **main** — T-38 MERGED `--no-ff` (`1942bb8`); M10 merge was `3f36fc3`.
- Suite: **270 tests / 701 assertions green** (`./vendor/bin/pest`).
- T-38 provider key UX + metallama knob gating — **DONE + REVIEWED**
  (Qwen commit `d495a6a`, my legacy-test fix `a539b4f`, brief
  `agents/T-38-provider-key-ux.md`). Key now settable at creation only;
  edit requires explicit `startChangeKey` (env-sourced builtins show
  "from env" badge, change refused); save NEVER touches the key outside
  changing mode; clear action kept. Draft `has_key` → `key_source`
  ('db'|'env'|'none') + `key_config`. Role drafts expose `knobs_inert`
  (provider driver === 'metallama') → 5 sampler inputs disabled + hint;
  `extra_instructions`/`timeout`/`model`/`max_tokens` stay editable.
  `updatedRoleDrafts` hook recomputes on provider switch. Owner has NOT
  yet signed off on the merge — flag it in next chat message.
- API key policy (owner-set 2026-07-16): env/config indirection preferred
  (builtins via `meta.api_key_config`); DB storage ONLY with encryption
  (`'api_key' => 'encrypted'` cast, mandatory); key never echoed to UI
  (`has_key` bool only) and never serialized — `$hidden` + regression
  test added in hardening commit `25e63da`. `resolvedApiKey()` is the
  only sanctioned read path. APP_KEY custody = the secret boundary; use
  `APP_PREVIOUS_KEYS` if rotating.
- T-35 provider_endpoints — **DONE + REVIEWED** (`8152c32`, `5359e5d`,
  review `6b0cf04`). provider_endpoints table (encrypted api_key), builtin
  rows track config/env via `meta.api_key_config`/`meta.base_url_config`,
  ProviderRegistry resolves role→endpoint→client preserving the
  `app()->instance(Provider::class, $fake)` test seam. SPEC §providers
  updated same-commit.
- T-36 Settings → Providers CRUD UI — **DONE + REVIEWED** (`b0d468f`,
  `0741176`, review `54430ed`). Write-only key field (blank=keep,
  clear action=null), /models test button, builtin/role-referenced delete
  refusals. Review fixes: `Livewire::test()` not `livewire()`,
  throwing-closure Http fake not `pushFault`.
- Wire-test PASSED (post-T-35): architect/reviewer → openrouter
  `https://openrouter.ai/api/v1` (key set) · builder → metallama
  `http://127.0.0.1:8010/ollama/v1` (no key). Snippet in DISPATCH.md.
- T-37 role developer view — **DONE + REVIEWED** (Qwen commits `ec80471`
  test + `77ea2b6` feat, review `1ed9bf8`). `roles.meta` knobs:
  `system_prompt_extra` (thinker roles, appended — JSON contracts stay in
  built-in prompt), `extra_instructions` (builder roles, `## Owner role
  instructions` block in task message), `top_p`/`frequency_penalty`/
  `presence_penalty`/`stop`/`timeout`. Review fixes: sequence() not
  invented pushJson(), real Project slug, numeric casts (JSON column
  demotes -1.0 → -1). SPEC §10 updated same-commit.
- M10 wire-verify **PASSED** (live, owner-requested): knobs set on global
  architect role → openrouter `/chat/completions` body carried
  `top_p: 0.9`, `presence_penalty: 0.1`, correctly OMITTED unset knobs
  (`frequency_penalty`, `stop`), and the `system_prompt_extra` canary was
  appended at the END of the system prompt (JSON contract text first).
  Real round-trip OK; role meta restored, scratch project deleted.
  **NEXT: merge `--no-ff` to main with owner sign-off** (asked, awaiting
  answer — do NOT merge unprompted).
- Owner tweaked actors/roles presentation — committed `5f224a3`; settings
  + role-dev-view tests green against it (17 tests / 35 assertions).
- Owner feedback → T-38 scope candidates: (1) API key settable only at
  provider creation, afterwards an explicit "Change API key" button
  (+ keep explicit clear action) instead of always-writable blank=keep
  field. (2) Sampler knobs (temperature/top_p/penalties/stop) are INERT
  on metallama-driver endpoints — models launch there with preset params;
  disable/hint those fields when the role's provider resolves to
  metallama. `extra_instructions` + `timeout` remain meaningful.
- Queue worker RESTARTED on T-38 main (task btcvcg3y3; harness,default;
  tries=1, timeout=1800) — restart again after ANY code change.
  NB: flag is `--queue` (singular); `--queues` errors out.
- Next task number: **T-39**. Next milestone: **M11** (contract detail
  view + project tabs Overview/Stats).

## Roadmap (owner-greenlit 2026-07-13)

- **M10** providers table + role dev view ← HERE
- **M11** contract detail view + project tabs (Overview/Stats)
- **M12** workspace live view (Reverb streaming)
- **M13+** workflow graph (engine A: nodes+edges → B: fan-out/fan-in →
  C: canvas; Drawflow candidate — NOT sigma.js)

## History (all merged to main, --no-ff, owner-gated)

M0–M1 foundation + aider/metallama harness go/no-go · M2 consensus +
plan-approval gate · M3 full build chain (wire-tested live) · M4 events,
Reverb timeline, Needs-You inbox, commit actions, usage ledger · M5 two-way
Telegram · M6 autonomy profiles + overnight batch · UX/Tailwind-4 passes
(T-23/T-24: zero style= in blades, tokens in app.css) · M9 Workflow Studio
(T-31 escalation loop, T-32 step objects, T-33 human nodes, T-34 editor v2)
· archive-projects. Task briefs T-01…T-37 in `agents/T-*.md`.

## Live processes (dev box)

- metallama on :8010 (user's own; restart rule in memory if RAM > 25GB).
- `php artisan serve --port=8890` (dev server).
- One queue worker — `php artisan queue:work --queue=harness,default
  --tries=1 --timeout=1800`. **Restart after ANY code change.**
- Reverb on :8815 (restart like the worker after code changes).

## Backlog (owner asks, not yet scheduled)

- Streaming replies in chat; approval-button hover feedback; distinct
  "awaiting approval" status color (tension w/ amber-owns-attention rule).
- All colors customizable in Settings (theme editor writing :root overrides).
- Answered question cards: unfold given answer + 'revise' affordance.
- Per-project cost dashboard (dataviz) on top of the usage ledger;
  frontier-spend cap consumes the same ledger (SPEC).

## Watch-outs

- Qwen failure modes + review checklist: see DISPATCH.md. Always run
  acceptance yourself.
- `.env` holds the OpenRouter key — never near git; `.gitignore` has
  `.env.*` catch-all; history scanned clean.
- Credits fine; Architect+Reviewer = deepseek/deepseek-v4-flash on
  openrouter. GLM 5.2 still a Reviewer candidate.

## HANDOFF — Fable 5 → Opus / GLM (written at Fable limit)

Read order for the incoming model: this file top block → agents/DISPATCH.md
(dispatch + review protocol + Qwen failure modes) → docs/SPEC.md.

- main @ `1942bb8`, pushed to origin. Suite 270/701 green.
- Queue worker task `btcvcg3y3` on current main (harness,default,
  tries=1, timeout=1800). Serve :8890 · Reverb :8815 · metallama :8010.
- IN FLIGHT: T-39 build on `feat/m11-project-tabs` via aider (brief:
  agents/T-39-project-tabs-overview.md). If the run finished: review per
  DISPATCH.md protocol (diff, full pest, acceptance greps, review(T-39)
  commit, worker restart, RESUME.md update). If it never started, summon
  aider yourself with the brief as edit targets + --read list.
- OWNER ITEMS PENDING: (1) explicit sign-off on the T-38 merge;
  (2) define "contract detail view" scope before T-40.
- Conventions that bit us: `--queue` singular on queue:work; verify every
  aider --read path exists; Livewire::test facade (no livewire() helper);
  restart worker after ANY code change; update RESUME.md after every task.
