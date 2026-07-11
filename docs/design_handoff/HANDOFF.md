# Majordom — Design Handoff

**Direction: "Quiet Console"** — dark-first, calm command. One accent (amber) owns
"needs you"; everything else stays quiet. Monospace accents, status LEDs,
restrained motion. Desktop-first; inbox + approvals are phone-ready.

The bundled `Majordom Directions.dc.html` is a **design reference built in HTML**
— a prototype showing intended look and behavior, not production code. Recreate
these designs in the Tailwind/Livewire codebase using its established patterns.
Fidelity: **high** — colors, type, spacing, and copy in the mockups are final
unless noted. Mockup ids referenced below: `1a` (project workspace), `2a` (home),
`2b` (inbox desktop), `2c` (inbox phone), `2d` (inbox empty), `2e` (settings).

---

## 1. Design tokens

### 1.1 Color — CSS variables

Semantic first; components never reference raw hexes. Structured so a light
theme swaps this block only.

```css
:root {
  /* canvas + surfaces (cool blue-black ramp) */
  --bg:            #0d1117;  /* app canvas */
  --surface:       #0f1520;  /* inset: inputs, composer, option rows */
  --surface-card:  #111823;  /* cards (project cards, mobile cards) */
  --surface-raised:#121926;  /* question cards, gate cards in chat */
  --surface-active:#151d29;  /* selected nav / active milestone row */
  --surface-chip:  #1a2230;  /* neutral pills, system chips */

  /* borders */
  --border:        #1e2836;  /* structural: panes, header, cards */
  --border-soft:   #161f2b;  /* row separators inside lists */
  --border-strong: #243044;  /* interactive: inputs, option rows */
  --border-hover:  #2a3646;  /* outline buttons, hover borders */

  /* text tiers */
  --text-hi:       #e8eef6;  /* headings, primary emphasis */
  --text:          #dce3ec;  /* titles in rows/cards */
  --text-body:     #c3cddb;  /* chat/message body */
  --text-2:        #9daabb;  /* secondary body */
  --text-3:        #8b98a9;  /* tertiary, inactive nav */
  --text-mute:     #5f6f83;  /* microcopy, mono metadata */
  --text-faint:    #48566a;  /* timestamps, hints, disabled-ish */

  /* THE accent — needs-you. Nothing else may use amber. */
  --accent:        #e2a33b;
  --accent-ink:    #181206;  /* text on solid accent */
  --accent-border: rgba(226,163,59,.55);
  --accent-tint:   rgba(226,163,59,.10);
  --accent-glow:   rgba(226,163,59,.60); /* LED box-shadow only */

  /* status */
  --status-idle:    #3d4a5c;
  --status-working: #579dd6;
  --status-failed:  #e5645c;
  --failed-text:    #ef8d86;
  --failed-border:  rgba(229,100,92,.45);
  --failed-tint:    rgba(229,100,92,.10);
  --ok:             #4f9e6f;  /* checkmarks, health LEDs, toggle-on */

  /* diff */
  --diff-add-bg:    rgba(63,185,80,.10);
  --diff-add-text:  #8fd9a8;
  --diff-del-bg:    rgba(229,100,92,.09);
  --diff-del-text:  #efa39d;
  --diff-hunk-bg:   #101724;
  --diff-hunk-text: #579dd6;
  --diff-plus:      #5fbf85;  /* +N counters */
  --diff-minus:     #e5837c;  /* −N counters */

  /* actor chips: tinted bg + toned text, all quiet */
  --actor-architect-bg: rgba(122,162,247,.14); --actor-architect: #9db8f0;
  --actor-builder-bg:   rgba(87,157,214,.12);  --actor-builder:   #79b4dd;
  --actor-reviewer-bg:  rgba(158,134,220,.14); --actor-reviewer:  #b3a2e3;
  --actor-you-bg:       rgba(79,158,111,.16);  --actor-you:       #79c99a;
  --actor-system-bg:    var(--surface-chip);   --actor-system:    #7c8a9c;
}
```

Tailwind: map each variable into `theme.extend.colors`
(e.g. `colors: { bg: 'var(--bg)', accent: 'var(--accent)', … }`) so utilities
read `bg-surface-card text-hi border-border`.

### 1.2 Type

**IBM Plex Sans** (UI) + **IBM Plex Mono** (console voice). Both OFL — bundle
woff2 locally, weights: Sans 400/500/600, Mono 400/500/600. No CDN.

The mono font is a **semantic marker**: statuses, event names, timestamps,
branch names, model ids, task ids (`T-15`), counts, keyboard-ish metadata.
Never body copy, never headings.

Scale (px / weight / notes):

| token | size | wt | use |
|---|---|---|---|
| `text-micro` | 10 | 500 | section microlabels — mono or sans, `tracking-[.14em] uppercase`, `--text-mute` |
| `text-meta` | 11 | 400 | mono metadata, timestamps |
| `text-caption` | 12 | 400 | hints, secondary rows |
| `text-body-sm` | 12.5–13 | 400 | list rows, tasks |
| `text-body` | 13.5 | 400 | chat body |
| `text-title-sm` | 14–15 | 500–600 | card/row titles |
| `text-title` | 16 | 600 | page headings |
| `text-display` | 20 | 600 | empty-state headline |

Line-height 1.5 body, 1.2 headings. Phone: bump one step (body 14, titles 15–16).

### 1.3 Spacing / radius / shadow

- **Spacing**: standard Tailwind 4px scale. Common rhythms: pane padding 16–18,
  page gutter 22–28, row padding-y 8–13, chip gaps 8–12.
- **Radius**: `rounded-md` 7–8px (rows, buttons, inputs) · `rounded-lg` 10px
  (cards in chat) · `rounded-xl` 12–14px (project/mobile cards) · `rounded-full`
  (pills, LEDs, badges).
- **Shadow**: essentially none — hierarchy comes from surface ramp + borders.
  The only glow is the needs-you LED: `box-shadow: 0 0 8px var(--accent-glow)`.

---

## 2. Component specs

### 2.1 Status LED
8px circle (`w-2 h-2 rounded-full`), inline with 8–10px gap.
- `idle` — `--status-idle`, static.
- `working` — `--status-working`, pulse animation (§4).
- `needs you` — `--accent` + glow shadow, pulse.
- `failed/parked` — `--status-failed`, **static** (errors don't breathe).
- `overnight` — no LED; a quiet mono note (`overnight queue: 2`, `--text-mute`).
6px variant inside task rows / tabs.

### 2.2 Status pill
Mono 10.5px/600, `px-2.5 py-0.5 rounded-full`, letter-spacing .06em.
- needs-you: `bg-accent-tint text-accent` (+ optional LED inside).
- neutral (working/idle): `bg-surface-chip text-3`.
- failed: `bg-failed-tint text-failed-text`.
Never solid-filled; pills are labels, not buttons.

### 2.3 Actor chip
Sans 10px/600 uppercase `tracking-[.1em]`, `px-2 py-0.5 rounded-[5px]`, tinted
bg + toned text per actor (tokens §1.1). Appears in chat messages and timeline
rows. Fixed vocabulary: Architect, Builder, Reviewer, You, System, Gate(=System
tint).

### 2.4 Buttons
Height 30–32 desktop, **44–48 phone**. `rounded-lg`, 12.5px/600.
- **Primary (accent)**: solid `--accent` / `--accent-ink`. Reserved for the ONE
  action that resolves a needs-you item (Answer, Approve). Max one per view.
- **Outline**: 1px `--border-hover`, text `#c7d2df`. Hover: border
  `--border-strong` lightened + `bg-surface-active`.
- **Danger outline**: 1px `--failed-border`, text `--failed-text`. Reject is
  never solid and never the largest target.
- **Pending (Livewire, mandatory on every actionable control)**: on click →
  instantly `disabled`, opacity .55, label swaps to inline 14px spinner +
  verb ("Approving…"). Buttons keep width (reserve with `min-width`). Use
  `wire:loading.attr="disabled"` semantics; round-trip is 100–300 ms so the
  spinner shows from 0 ms — no delay threshold.
- **Disabled (precondition)**: `bg-surface-chip text-mute`, e.g. "Approve plan
  v2" while questions remain open, with a mono hint ("unlocks after Q2").

### 2.5 Question card (Consensus chat)
`bg-surface-raised`, 1px border, `rounded-lg`, padding 14–16. Max-width 640px.
- **Open**: border `--accent-border`; header microlabel
  `OPEN QUESTION n/N` in accent + right-aligned mono context ("blocks plan
  approval"). Question text `--text` 13px. Options as radio rows: `bg-surface`
  1px `--border-strong` `rounded-md`, 14px radio circle; selected row gets
  accent border + accent-filled radio. Footer: primary **Answer** button +
  mono hint (`sends to Architect · ~2 s`).
- **Answered**: collapses to a single header row — checkmark (`--ok`), muted
  title, mono `answered · 15 min`; border back to `--border-strong`,
  opacity .75.
- **Gate indicator**: pill in chat header — `1 question remaining`, accent
  outline style. Zero → pill disappears entirely (quiet by default).

### 2.6 Plan-approval moment
A distinct card (same anatomy as question card) with `PLAN APPROVAL`
microlabel, one-line consequence copy ("Approve plan v3 → Architect cuts M2
into tasks…"), primary Approve + outline Request-changes. While questions
remain open it renders in the **disabled** state (§2.4).

### 2.7 Review gate card (inline in chat) & diff viewer
- **Gate card in chat** (`1a`): Gate chip + title "Review requested — T-15",
  right mono `tests 41 ✓` in `--ok`; meta row mono (`3 files +93 −8 · builder:
  qwen2.5-coder`); action bar: Approve (primary) / Reject (danger outline) /
  Comment (outline) + right-aligned mono escape hatch **open in VS Code ↗**.
  Clicking the card expands the full diff viewer below it in the chat column.
- **Diff viewer**: unified diff, IBM Plex Mono 12px, line-height 1.75.
  File list rows: filename mono `--text`, `+N` `--diff-plus` / `−N`
  `--diff-minus`, collapse chevron (`⌄` open / `›` closed), separated by
  `--border-soft`. Hunk header `--diff-hunk-text` on `--diff-hunk-bg`. Added
  lines `--diff-add-bg`/`--diff-add-text`; removed `--diff-del-bg`/
  `--diff-del-text`; context `--text-3`. Full-width rows, code at
  `padding-x 16`. No syntax highlighting needed v1.

### 2.8 Timeline event row
Right pane, rows separated by `--border-soft`, `py-2.5 px-4`.
Line 1: mono timestamp (`--text-mute`) · mono event name (`--text`, 11.5/500,
dot-namespaced: `review.requested`, `tests.passed`) · actor chip.
Line 2: one-line payload summary, 12px `--actor-system` tone.
Rows that created a needs-you item get `bg-accent-tint`.
**Live insert (WebSocket)**: new row enters at top — fades in from
`opacity 0` + `max-height 0 → auto` over 240 ms ease-out, then settles; no
layout jump below the fold. If the user has scrolled down, do NOT auto-scroll;
show a quiet `↑ new events` mono affordance at the pane top instead.

### 2.9 Roadmap rail
Milestones: row `py-1.5 px-2 rounded-md`; done = `--ok` check + `--text-3`;
active = `bg-surface-active`, working LED, `--text` 500; future = hollow 6px
dot (`1px --status-idle` border). Tasks indent under active milestone: mono id
(`T-15`) in `--text-mute` + label; states mirror milestone iconography. Task at
a gate: `bg-accent-tint` row + accent LED + accent "at gate" note.
Editable affordance: `+ add task` ghost row in `--text-faint`.

### 2.10 Inbox row (desktop, `2b`)
Full-width row, `py-3 px-6`, `--border-soft` separators.
Anatomy: needs-you LED · **type microlabel** (118px fixed column, mono
uppercase 10px — `QUESTION`(accent) / `ARBITRATE` / `APPROVE PLAN` /
`MANUAL TEST` / `COMMIT READY`) · title (13.5 `--text`) over mono context line
(project · qualifier, 11px `--text-mute`) · mono timestamp · **one** action
button (accent outline for the top item, outline otherwise; label ends with
`›`). Row hover: `bg-surface`. Resolved rows animate out (§4).

### 2.11 Mobile inbox card (`2c`)
`bg-surface-card rounded-[14px] p-4`, 10px gap stack. First (or tapped) item
**expands**: type microlabel + timestamp, 15px title, 13px context copy, mono
"view full plan ›" link, then a full-width **48px** primary button; secondary
row of two 44px buttons (Comment / Reject). **Destructive verdicts never sit
in the thumb's resting zone** — Reject is bottom-right, half-width, danger
outline. Collapsed cards: one-line — microlabel, title + project, `timestamp ›`.
All targets ≥ 44px.

### 2.12 Empty / edge states
- **Empty inbox (`2d`)** — a reward: three LEDs (idle·idle·working), "All
  quiet" 20px/600, "Nothing needs you. The estate runs itself tonight.", mono
  next-overnight note. Centered, generous space.
- **New project** — same pattern: project name, idle LED, "No memory yet.
  Describe the first feature to wake the Architect." + composer focused.
- **Parked on error (`2a` atlas-sync card)** — card border `--failed-border`,
  static failed LED, failed pill (`parked · metallama offline`), mono event
  line + small outline **Retry**. Muted, not alarming.
- **Loading (initial page)** — skeleton rows in `--surface-chip` with a slow
  1.6 s opacity pulse; no spinners for page loads (spinners are for actions).

### 2.13 Settings (`2e`)
Left nav 224px (`--surface-active` selected row); content max-width 760px.
Form rows: 170px label column / control / mono service note / health LED
(`--ok` static). Inputs: `bg-surface` 1px `--border-strong` `rounded-lg`
13px. Toggle: 36×20 pill, `--border-strong` off / `--ok` on, 16px knob,
120 ms slide. Save = primary accent + pending state.

### 2.14 Global nav
52px bar, `--border` bottom. Mono brand `MAJORDOM` (12px, tracking .18em) ·
sans nav links (`--text-3`; active `--text-hi` 500) · Inbox with solid accent
count badge (10.5px/600, `--accent-ink` on `--accent`) — the badge is the only
persistent accent in chrome; hide at zero. Right: mono overnight note + 26px
avatar circle.

---

## 3. Screen layouts

### 3.1 Home — project dashboard (`2a`)
Nav bar → page heading row (`Projects` + mono summary "1 needs you · 1 working
· 1 parked") → wrapping card grid (300px cards, 18px gap, 28px gutters).
Card anatomy: name + LED / status pill / active milestone + task progress /
mono last-event line. Needs-you card gets accent border + tint; parked card
failed border + Retry; last cell is a dashed "+ New project" ghost.
Cards sort: needs-you → working → idle → parked. Responsive: grid wraps;
single column under 640px.

### 3.2 Project workspace (`1a`)
Three regions, chat primary:
- **Left rail 264px** — project identity (name, LED, mono repo URL), status
  pill, ROADMAP microlabel + milestone/task list (§2.9). Collapsible to 0 on
  narrow desktop.
- **Center — Consensus chat (flexible)** — header (session title, Architect
  chip, mono model id; right: gate pill "N questions remaining"); message
  stream (720px max message width) interleaving: messages, question cards,
  plan-approval card, review-gate cards; composer pinned bottom (`--surface`
  inset, outline Send). Streaming text shows a 7×14px accent-blue caret block
  blinking at 1 s steps.
- **Right — Activity timeline 330px** — ACTIVITY microlabel + working LED +
  mono `live`; event rows (§2.8). Collapsible.
Review happens **inline in chat** (gate card → expands diff). Below ~1100px
the timeline collapses behind a toggle; below ~880px the rail too.

### 3.3 Needs-You inbox (`2b` desktop, `2c` phone, `2d` empty)
Desktop: heading + accent count pill + project filter select; flat queue,
newest first, one row per item (§2.10). Resolving a row from anywhere (even
another device) removes it live.
Phone: stacked cards (§2.11), pull-to-refresh optional (WS makes it
redundant). Empty state §2.12.

### 3.4 Settings (`2e`)
§2.13. Sections: Actors & roles / Services & models / Workflow / Integrations.
Conventional; tokens applied; no cleverness required.

---

## 4. Motion rules

Restrained. Server round-trips mean motion covers latency, never simulates it.

| what | how |
|---|---|
| LED pulse (working, needs-you) | opacity 1 → .35 → 1, **2.4 s** ease-in-out infinite |
| Streaming caret | 1 s `steps(2)` blink |
| Button → pending | instant (0 ms) disable + spinner swap; no fade |
| New timeline row | 240 ms ease-out fade + height reveal, top-insert, no auto-scroll |
| Inbox row resolved | 180 ms fade + height collapse |
| Question card open→answered | 200 ms height collapse to header row |
| Toggle knob | 120 ms ease |
| Hover states | 120 ms background/border transitions |
| Skeleton loading | 1.6 s opacity pulse |

**Never animates:** layout/pane widths, page transitions, badge counts
(swap instantly), the failed LED, anything scroll-linked. No parallax, no
springs, no motion above 300 ms except the LED pulse.

---

## 5. Do / Don't

**Do**
- Reserve amber exclusively for needs-you. If a new feature "needs attention",
  it joins the needs-you semantics — it doesn't get a new loud color.
- Use mono for machine-voice text only (ids, events, timestamps, models,
  branches, counts) — it's what makes the console feel without RGB.
- Give every Livewire action an instant pending/disabled state, 0 ms.
- Keep one primary action per view; verdict buttons: Approve solid, Reject
  danger-outline, always with a Comment escape.
- Let quietness be the default: zero badges hide, gate pills disappear at
  zero, empty states read as a reward.
- Add new statuses to the ramp by darkness, not saturation.

**Don't**
- No solid red buttons; no red anywhere except failed states and diff
  deletions.
- No gradients, glassmorphism, neon glows (except the 8px needs-you LED
  shadow), or scanline effects.
- No emoji in UI copy.
- No auto-scrolling the user's viewport when live events arrive.
- No toasts for events already shown in the timeline — the timeline IS the
  notification surface on desktop.
- No spinners for page loads (skeletons) — spinners belong to user-initiated
  actions only.
- Don't put Reject where a thumb rests on mobile; don't make it the largest
  target anywhere.

---

## 6. Assets & files

- Fonts: IBM Plex Sans + IBM Plex Mono — download from the IBM Plex GitHub
  releases (OFL license), self-host woff2.
- No icons required for v1: LEDs, dots, ✓, chevrons (`›`, `⌄`) and arrows are
  typographic. If an icon set is wanted later, use a 1.5px-stroke outline set
  (e.g. Lucide) at 16px, `--text-mute`.
- `Majordom Directions.dc.html` + `support.js` — the interactive mockup canvas
  (open the HTML in a browser; requires `support.js` alongside). Turn 2 =
  final screens, turn 1 = the chosen workspace direction `1a` (ignore
  discarded options `1b`/`1c`).
