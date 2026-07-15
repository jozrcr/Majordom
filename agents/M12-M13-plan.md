# Post-M11 plan — M12 (autonomy loop) + M13 (workspace UX)

Captured from owner e2e test feedback (2026-07-16). M11 merged to main (64ece38).

## M12 — Autonomy loop (PRIORITY, owner-greenlit)
Model confirmed: **tasks always full auto-chain** within a milestone; the
**milestone boundary always gates** (consent to merge-to-main + start next).
Autonomy profile governs the per-task human checkpoints:
- Attended: auto-chain but ping at each task's review + human-test gate.
- Overnight: auto-pass review/test checkpoints; whole milestone runs unattended;
  escalations + milestone-merge consent collect in the morning inbox.
Always-gated regardless of profile: reviewer escalations; milestone boundary.

Pieces to build (spec into tasks):
1. **Decompose** (NEW ArchitectService capability): generate the next task's
   `task.md` brief on-demand from roadmap.md + architecture.md + the task's
   roadmap title + prior committed work. (T-002…T-020 currently have NO briefs —
   only titles from the roadmap; this is the missing engine.)
2. **Milestone integration branch**: tasks in a milestone accumulate on
   `majordom/M<n>` (each task auto-commits there, no per-task commit gate);
   milestone-boundary consent merges `majordom/M<n>` → main.
3. **Post-commit advance**: on a task's commit → resolve next pending task in the
   same milestone (Milestone hasMany Task, by position); decompose + auto-start.
4. **Milestone gate**: last task of a milestone done → Approval "M<n> complete
   (N tasks) — merge into main? / start M<n+1>?". Human consents.
5. UI: reflect auto-progression + the milestone gate card. Relabel the commit
   gate as "Merge into <branch>" (folds in the old T-45 idea).

## M13 — Workspace UX overhaul (owner e2e feedback)
### Overall
- **Common header** across all tabs: Project name · folder · status (context
  always reachable).
- **Project Settings tab**: rename, archive, (room for more later).

### Chat
- Approve-plan button: real style + hover/click feedback (currently none).
- Start-build button: hover/feedback.
- **Activity panel rework**:
  - Header per section = milestone · task number (not "execution #xx").
  - Sections default to CLOSED accordions (reduce clutter). [Claude: agree —
    current execution open, past ones collapsed, summary line on each.]
  - Group answered questions together (they spam the column).
- **Answer UX**: don't require copying the question into the answer; add a
  "Send answers" button at the bottom to submit all checked answers at once.
- **Consensus plan popup**: summary in chat + a "view full plan" detail popup
  (for later).
- **Commit approval popup**: proper in-app modal with the detailed diff/message
  view (replaces the cramped textarea); relabel "Commit" → "Merge into <branch>".
- **Commit warning**: make the "uncommitted changes" warning a proper in-app
  popup, not inline text.
- **Remove "Reject" button** [Claude: agree — keep Commit + Rework; Rework(comment)
  covers "no, redo it". A true task-abandon is rare → later explicit action].

### Overview
- Change "Agreed plan" (dup of Roadmap) → a **project summary**: the agreed
  principle/goal, not the task realization.
- Display the **agreed specs** here.
- Group recent-consensus answers together (anti-spam).

### Not touching (owner: "look very good")
- Stats + Roadmap tabs.

## Sequencing
M12 first (priority — the autonomy loop is the product's spine). M13 UX in
parallel/after. Several M13 items fold into M12 (commit→merge relabel, overview
project-summary). Dispatch order TBD after M12 spec.

## E2E test #2 feedback (2026-07-16)

### Fixed immediately (on feat/m12-autonomy-loop)
- **build "failed" on a satisfied no-op** — harness hard-failed empty diffs; now
  an empty diff after a clean run is a completed no-op → flows to review
  (`85782ff`).
- **stuck after a bad clarification** — no way to discard a question; added
  QuestionStatus::Discarded + Discard action (`a9a8f9c`).

### Flow bugs — need design (higher priority than UX polish)
- **Post-plan / post-abandon re-chat is broken**: after a plan is approved (or a
  run abandoned), chatting with the Architect again "bugs out". `converse()` has
  no notion of "re-engage after planning" — it just appends a turn. NEEDS: a
  defined re-engage flow (revise scope / re-plan / restart a task) that doesn't
  corrupt the consensus/plan state. DESIGN QUESTION for owner: what should
  "talk to the architect after a plan" do — amend the plan? start a new
  consensus round? re-decompose? This is the next big flow piece (call it M14
  flow-robustness).
- **Failure handling generally**: build-failed gave no context ("what failed
  where"). Want: retry/abandon via Telegram WITH context (the reviewer verdict /
  rawLog tail / which task+revision). Ties into the failure-flow redesign.

### UX (M13)
- Switch autonomy profile AFTER project start (change to overnight/full_auto mid-
  flight). Currently profile is set at build start per execution; needs a
  project-level profile control that new executions pick up.
- Approve-plan button → confirmation popup.
- Roadmap task accordion → move task detail into a POPUP (button), too crammed
  inline. Add buttons for the reviewer's review too (per-task exchange/verdict).

### Reviewer quality note
- deepseek reviewer answered in Chinese once. Watch for reviewer-output quality;
  GLM 5.2 still a candidate. Not a code bug — model behavior.
