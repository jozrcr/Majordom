# Session resume — state as of 2026-07-16 (T-39 reviewed on branch; awaiting merge sign-off)

## T-39 status (Opus session)

- T-39 (project tabs Chat/Overview/Stats) BUILT by Qwen (`1c38d56`,
  `07fd690`) + REVIEWED by Opus (`9b156a8`) on `feat/m11-project-tabs`
  (pushed to origin). Suite **274/274 green**.
- Review fixes: partials referenced bare `$plannedTask`/`$recentConsensus`/
  `$usageStats`/`$executionCounts` — computed props need `$this->` inside
  `@include`; added `updatedTab()` so live tab changes (not just mount)
  normalize unknown values → chat; test UsageRecord needs `model` (NOT
  NULL); SPEC §9 documents the tabbed workspace.
- NOT merged — merges to main are owner-gated (T-38 precedent). Awaiting
  owner "green light" on the T-39 merge.
- Owner now runs their OWN dev stack via `npm run dev` (concurrently:
  serve/worker/reverb/telegram/vite, `--kill-others`). My standalone
  background worker was stopped to avoid a duplicate — DO NOT spawn a
  separate `queue:work`; the owner's stack owns processes now. T-39 is
  UI-only so no worker restart was needed regardless.
- T-40 scope is DEFINED (exchange trace over `events` table) — see
  DISPATCH.md M11/T-40. Brief not yet written.

## T-41 status (Opus session, in flight)

- Design SETTLED w/ owner: source = `agents/ROADMAP.md` (per-project at
  `{repo_path}/agents/ROADMAP.md`); one-way md→DB sync; git + light
  `roadmap_events` feed for history; live DB status overrides md declared
  status UPWARD only. ROADMAP.md authored w/ real M1–M11 history.
- Brief `agents/T-41-roadmap-tab.md` + ROADMAP.md committed `7eef425`.
- Qwen build (`1779aaf`, `604d3d6`) + REVIEWED by Opus (`5018308`).
  **Suite 282/282 green.** Review fixes: (1) Task `$fillable` missing
  milestone_id/position/declared_status — mass-assignment silently dropped
  them (my brief scoped Task.php read-only — my miss); (2) deriveStatus:
  done+todo mix is ongoing not done; (3) real stored plan text (planText
  prop reads roadmap.md/architecture.md/plan_draft.md) replaces Qwen's
  hardcoded placeholder; (4) File::ensureDirectory→ensureDirectoryExists;
  +2 tests; SPEC §10.1 augmented.
- WIRE-TESTED live: seeded a **"majordom" self-project** (slug=majordom,
  repo_path=base_path, project id=2) and synced the real agents/ROADMAP.md
  → 11 milestones / 35 tasks; M1–M10 derive done, M11 ongoing; idempotent
  (2nd sync 0 new events). Owner can view it in-browser now.
- T-41 done + reviewed on `feat/m11-project-tabs`.

## T-43 status (Opus session) — structured DB-derived roadmap

- Owner refined the vision: Roadmap tab must render **DB entities, never raw
  md**; Architect writes structured roadmap.md (milestones + nested task
  checkboxes); 3-level accordion (milestone → task → description). Metrics
  deferred to **T-44** (owner-requested; note in DISPATCH.md).
- Qwen build (`bd991d1`, `150817d`) — **CLEAN, no review fixes needed.**
  Suite **285/285 green** (incl. Architect/plan-flow tests — the PLAN_PROMPT
  change was safe). RoadmapSync now: source = repo agents/ROADMAP.md else
  memory roadmap.md; parser tolerates structured `## M<N> —` AND legacy
  `## Milestone N:` prose; task `description` synced from tasks/<key>/task.md.
  Raw-md blob removed from Roadmap tab.
- WIRE-TESTED: test-joac (legacy prose) → 5 milestone accordions w/
  summaries (fixes the screenshot textarea complaint); majordom self-project
  (structured) → M11 with 4 nested tasks, correct effective statuses.
- T-43 done + em-dash /u fix (`31777cc`) + owner roadmap scroll tweak (`350b9ff`).

## T-40 + T-44 status (Opus session) — exchange trace + metrics

- **T-40 exchange trace** (`a0bc7d7`,`04051b8`, review `a982f9c`): new
  `exchanges` tab. `ExchangeTrace::for(Execution)` projects the events table →
  ordered actor→actor hops (instruction/result/rework/verdict/…), excerpt +
  Alpine-expandable full text, per-role usage strip. Wire-tested exec #13: the
  4-revision rework loop → parked reads clearly. Review fix: test harness
  (RefreshDatabase/slug/model).
- **T-44 milestone metrics** (`fc03755`,`feded7c`, review `f8a9495`):
  `MilestoneMetrics::forMilestone/forTask/forTasks` → tokens by role, cost,
  human interventions, rework cycles, files changed, time-to-completion
  (`tests_added` deferred/null). Surfaced in **Stats tab** as per-milestone
  accordions w/ per-task drill-down. Review fixes: created missing
  MilestoneFactory, design-token colors (not raw Tailwind), RefreshDatabase,
  float-delta assert. Null-safe on never-run milestones (clean zeros).
- **Suite 293/293 green.** No inline styles anywhere.
- STATUS: **M11 COMPLETE** on `feat/m11-project-tabs` — 5 tabs (Chat,
  Overview, Stats+metrics, Roadmap, Exchanges), all reviewed + wire-tested +
  pushed. **Merge-ready pending owner visual confirm + sign-off.** Metrics
  populate for NEW structured-roadmap projects (legacy prose projects show
  milestones but zero-metric until tasks link to executions).

---

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
  `updatedRoleDrafts` hook recomputes on provider switch. Owner
  SIGNED OFF on the merge 2026-07-16 ("green light on T 38 merge").
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
- IN FLIGHT: T-39 build on `feat/m11-project-tabs` via aider — background
  task `b1f3iyeby`, log `/tmp/claude-1000/-home-joz-Documents-AI-Tools-Majordom/724fe8bd-c6e4-4bf5-917d-f731c8d77c32/tasks/b1f3iyeby.output`.
  Startup verified 2026-07-16: v0.86.2, whole edit format, 5 edit targets
  + 5 read-only files in chat (brief:
  agents/T-39-project-tabs-overview.md). If the run finished: review per
  DISPATCH.md protocol (diff, full pest, acceptance greps, review(T-39)
  commit, worker restart, RESUME.md update). If it never started, summon
  aider yourself with the brief as edit targets + --read list.
- OWNER ITEMS RESOLVED 2026-07-16: (1) T-38 merge signed off.
  (2) T-40 "contract detail view" DEFINED: condensed actor→actor
  exchange trace (architect→builder instructions, builder→reviewer
  results, verdicts) as a projection over the existing `events` table
  (EventRecorder payloads), per-execution sequence view with excerpts +
  expandable full text; enrich payloads at emission seams where
  instruction text is missing; NO log parsing, NO LLM summaries in v1.
  Scope details in DISPATCH.md M11/T-40.
- Conventions that bit us: `--queue` singular on queue:work; verify every
  aider --read path exists; Livewire::test facade (no livewire() helper);
  restart worker after ANY code change; update RESUME.md after every task.
