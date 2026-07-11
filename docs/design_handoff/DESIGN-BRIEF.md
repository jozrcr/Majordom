# Majordom — Design Brief (handoff to Claude design)

> Self-contained brief for a design session. The designer has **no access to the
> repo** — everything needed is in this document. The deliverable is a written
> **design handoff** (spec, not code) that an engineer implements in
> Tailwind/Livewire without guessing.

## What Majordom is (60 seconds)

Majordom is a **personal, self-hosted AI orchestration platform for software
engineering**. One user. You register a git repository, describe a feature, and
Majordom runs the workflow: a frontier-model **Architect** asks you every open
question, agrees on a plan, and cuts it into tasks; a local-model **Builder**
implements them; a frontier **Reviewer** judges the diffs; automated tests gate
everything; and **you** — the owner — approve, manually test, and hold sole
commit authority. Runs can be queued overnight; you review the pile in the
morning from a global **"Needs You" inbox**, including from your phone.

The name: a majordom is the chief steward of an estate — coordinates the staff,
keeps the ledgers, carries the messages, never signs in the owner's name.

**The emotional core: calm command.** The user is a commander reviewing work in
a quiet ops room — not an operator babysitting a terminal. Design for daily,
long-session professional use.

## Design goal

A **design system + annotated layouts for four surfaces**, delivered as a
written handoff. Dark theme is the primary deliverable; tokens should be
structured so a light theme can be derived later without redesign.

## Aesthetic direction (binding, from the spec)

- **Modern, dark-first, calm.** A subtle **command-console** aesthetic:
  monospace accents (statuses, branch names, event names, model ids), status
  LEDs, restrained motion. Explicitly **not** a gamer-RGB dashboard.
- Information-dense but breathable — a cockpit, not a brochure.
- One accent must own the hierarchy: **"needs you"** is the state the entire UI
  is organized around. Everything else stays quiet so it can pop.
- Should feel visibly distinct from its sibling tool metallama (a utilitarian
  model-manager dashboard). If a reference is needed, ask the owner for a
  screenshot.

## Hard constraints (engineering realities)

- **Tailwind CSS.** Deliver tokens as CSS variables plus a Tailwind-friendly
  scale (colors, spacing, radii, type).
- **Livewire 3, server-rendered.** Most interactions round-trip to the server
  (~100–300 ms). Every actionable control needs a pending/disabled affordance;
  nothing may depend on instant client-side state or 60 fps interaction.
- **Real-time pushes** (WebSockets): timeline rows, status lights, and inbox
  counts update live. Design how new rows appear (and settle) without jumping
  the page.
- **Desktop-first**, but the **inbox and all approval flows must work well on a
  phone** — "approve from bed" is a core scenario.
- Self-hosted: no CDN dependencies. Pick a bundleable font pair (sans +
  mono) with open licensing.

## Screens to design (priority order)

1. **Home — project dashboard.** Cards per project: name, active milestone,
   status light (`idle` / `working` / `needs you`), last activity. Global nav
   with an inbox badge count.
2. **Project workspace** — the main screen. Four regions whose arrangement is
   the designer's call (tabs, panes, collapsibles — propose one):
   - **Consensus chat** (primary surface): converse with the Architect.
   - **Roadmap**: milestones → tasks, editable.
   - **Activity timeline**: the live event feed (delegated → building → review…).
   - **Review surface**: appears at a gate — inline diff viewer with
     approve / reject / comment, plus an "open in VS Code" escape hatch.
3. **"Needs You" inbox** — one global queue across all projects. Item types:
   answer-a-question, approve-plan, arbitrate-review, manual-test invite,
   commit-ready. This is the morning-review backbone. **Mobile layout
   required.**
4. **Settings** — actors/role bindings, services/models, workflow params,
   integrations. Conventional forms; low design effort acceptable, tokens
   applied consistently.

## Key components & states

- **Status semantics:** `idle` (neutral), `working` (subtle live indicator),
  `needs you` (THE accent), `failed/parked` (error, e.g. "metallama offline"),
  `overnight` (queued/unattended).
- **Consensus chat:** streaming assistant text; **question cards** — the
  Architect's open questions rendered as discrete answerable items with inline
  inputs; a "questions remaining: N" gate indicator; a distinct plan-approval
  moment.
- **Diff viewer:** unified diff, file list with per-file collapse, monospace,
  add/remove coloring that fits the palette, approve/reject/comment bar.
- **Timeline event row:** timestamp, event name (mono), actor chip
  (Architect/Builder/Reviewer/You/System), one-line payload summary.
- **Approval controls:** approve / reject / comment. Unambiguous, hard to
  fat-finger on mobile, satisfying to use.
- **Empty/edge states:** new project (no memory yet), empty inbox ("all
  quiet" should feel like a reward), loading, execution parked on error.

## Deliverable back (the design handoff)

One self-contained markdown document (optional: HTML mockups, self-contained,
no external assets):

1. **Design tokens** — semantic color palette as CSS variables (bg, surface,
   raised-surface, borders, text tiers, accent, per-status colors), type scale
   + the font pair, spacing/radius/shadow scales.
2. **Component specs** — each component above: anatomy, all states
   (default/hover/pending/disabled/error), notes at Tailwind level.
3. **Screen layouts** — annotated structure for the four screens (regions,
   hierarchy, responsive behavior), plus the mobile inbox/approval layout.
4. **Motion rules** — what animates (and duration/easing), what never does.
5. **Do/don't list** — so future screens stay consistent without the designer.

Illustrative snippets are welcome; production code is not expected — the
engineer implements from the spec.
