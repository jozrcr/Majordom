# Majordom — Roadmap

The canonical milestones → tasks plan for this project. Hand-authored.
`RoadmapSync` parses this file (per project, at `{repo_path}/agents/ROADMAP.md`)
and mirrors it into the DB for the project Roadmap tab. This file is the
source of truth for *structure*; live execution status in the DB overrides the
declared status upward (todo → ongoing → done).

Status markers: `[ ]` todo (grey) · `[~]` ongoing (amber) · `[x]` done (green).
A milestone's status is derived from its tasks (all done → done; any ongoing → ongoing; else todo).

## M1 — Foundations
Auth, the Project model, and the project dashboard shell.

- [x] T-01 — Token auth
- [x] T-02 — Project model
- [x] T-03 — Project dashboard

## M2 — Local model + harness
metallama gateway, the resource coordinator, and the aider build harness.

- [x] T-04 — metallama client
- [x] T-05 — Resource coordinator
- [x] T-06 — aider harness

## M3 — Providers + consensus core
Provider client, the consensus domain, and the per-project memory store.

- [x] T-08 — Provider client
- [x] T-09 — Consensus domain
- [x] T-10 — Memory store

## M4 — Consensus chat
The live consensus/activity chat surface.

- [x] T-12 — Consensus chat

## M5 — Workflow + worktrees
The workflow domain and the git worktree manager.

- [x] T-13 — Workflow domain
- [x] T-14 — Worktree manager

## M6 — Pipeline + execution
Pipeline nodes, execution UI, event log, and the live timeline.

- [x] T-16 — Pipeline nodes
- [x] T-18 — Execution UI
- [x] T-19 — Event log
- [x] T-20 — Live timeline

## M7 — Inbox + usage
The "needs you" inbox, the usage ledger, and timeline detail/grouping.

- [x] T-21 — Needs-you inbox
- [x] T-22 — Usage ledger
- [x] T-23a — Timeline details
- [x] T-23b — Session grouping

## M8 — Telegram + roles
Telegram client and the roles domain.

- [x] T-25 — Telegram client
- [x] T-28 — Roles domain

## M9 — Workflows authoring
Settings screens, workflow domain, step objects, human nodes, editor v2.

- [x] T-29 — Settings screens
- [x] T-30 — Workflows domain
- [x] T-32 — Step objects
- [x] T-33 — Human nodes
- [x] T-34 — Workflow editor v2

## M10 — Provider endpoints
Provider endpoints, the settings UI, the role/developer view, and key UX.

- [x] T-35 — Provider endpoints
- [x] T-36 — Providers settings UI
- [x] T-37 — Role developer view
- [x] T-38 — Provider key UX

## M11 — Project workspace tabs
Tabbed project workspace: Overview, Stats, Roadmap, and the exchange trace.

- [x] T-39 — Project tabs + Overview + Stats
- [~] T-41 — Roadmap tab (milestones/tasks synced from this file)
- [ ] T-40 — Exchange-trace / contract detail view
- [ ] T-42 — Stats graphs (date-filterable)
