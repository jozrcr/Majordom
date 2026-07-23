<?php

namespace App\Livewire;

use App\Agents\Architect\ArchitectService;
use App\Core\Events\EventRecorder;
use App\Enums\ApprovalType;
use App\Enums\QuestionStatus;
use App\Jobs\RunArchitectTurn;
use App\Models\Node;
use App\Models\Project;
use App\Projects\Exchanges\ExchangeTrace;
use App\Support\Setting;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

class ProjectWorkspace extends Component
{
    public Project $project;
    #[Url]
    public string $tab = 'chat';
    public string $draft = '';
    public array $answerDrafts = [];
    /** Free-text answers; when non-empty they win over a picked option. */
    public array $customDrafts = [];
    public ?string $gateComment = null;
    /** M16-A: lazily-loaded cumulative diff for the milestone merge gate. */
    public bool $showMilestoneDiff = false;
    public ?string $milestoneDiff = null;
    public ?int $selectedEventId = null;
    public ?int $workflowId = null;
    public ?int $selectedExecutionId = null;
    public ?int $inspectedNodeId = null;
    public string $settingsName = '';

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->workflowId = $project->workflow_id;
        $this->settingsName = $project->name;
        $this->normalizeTab();
    }

    public function updatedTab(): void
    {
        $this->normalizeTab();
    }

    private function normalizeTab(): void
    {
        if (!in_array($this->tab, ['chat', 'overview', 'stats', 'roadmap', 'exchanges', 'settings'], true)) {
            $this->tab = 'chat';
        }
    }

    public function updatedWorkflowId(?int $value): void
    {
        $this->project->update(['workflow_id' => $value]);
    }

    public function send(): void
    {
        $this->validate([
            'draft' => 'required|string|max:8000',
        ]);

        Cache::put("architect-turn:{$this->project->id}", 'thinking', now()->addMinutes(15));
        $this->project->update(['status' => \App\Enums\ProjectStatus::Working, 'last_activity_at' => now()]);

        RunArchitectTurn::dispatch($this->project->id, $this->draft)
            ->onConnection('harness')
            ->onQueue('harness');

        $this->draft = '';
    }

    public function getPlanExistsProperty(): bool
    {
        return $this->project->consensusMessages()
            ->where('role', \App\Enums\MessageRole::System)
            ->get()
            ->contains(fn ($m) => ($m->meta['planWritten'] ?? false) === true);
    }

    /**
     * The plan the Architect captured via propose_plan and is awaiting approval
     * on (M16-B recap). Read from the latest consensus-claiming Architect
     * message so the approval card can show what the owner is agreeing to —
     * summary + roadmap — BEFORE they approve. Null unless a plan is pending.
     *
     * @return array{summary?: string, roadmap_md?: string, architecture_md?: string, first_task_id?: string}|null
     */
    public function getProposedPlanProperty(): ?array
    {
        if (! $this->consensusPending) {
            return null;
        }

        $last = $this->project->consensusMessages()
            ->where('role', \App\Enums\MessageRole::Architect)
            ->orderByDesc('id')
            ->first();

        $plan = $last?->meta['proposed_plan'] ?? null;

        return is_array($plan) ? $plan : null;
    }

    public function answerQuestion(int $questionId): void
    {
        $question = $this->project->questions()->findOrFail($questionId);

        if ($question->status !== QuestionStatus::Open) {
            return;
        }

        $custom = trim($this->customDrafts[$questionId] ?? '');
        $text = $custom !== '' ? $custom : trim($this->answerDrafts[$questionId] ?? '');

        if ($text === '') {
            $this->addError("answer-{$questionId}", 'Pick an option or write an answer.');
            return;
        }

        app(ArchitectService::class)->answer($question, $text);

        // Reviewer-escalated questions resume the execution, not the chat.
        if ($question->execution_id) {
            $execution = $question->execution;
            if ($execution && $execution->questions()->open()->count() === 0) {
                app(\App\Core\Workflow\WorkflowEngine::class)->resumeAfterClarification($execution);
            }

            return;
        }

        if ($this->project->openQuestions()->count() === 0) {
            Cache::put("architect-turn:{$this->project->id}", 'thinking', now()->addMinutes(15));
            RunArchitectTurn::dispatch($this->project->id, null)
                ->onConnection('harness')
                ->onQueue('harness');
        }
    }

    /**
     * Dismiss a question the owner can't or won't answer (e.g. the model asked
     * something malformed or answered itself unhelpfully) so it stops blocking.
     * A discarded question is ignored — for a reviewer escalation the loop
     * re-arms without that clarification; for consensus the Architect re-prompts.
     */
    public function discardQuestion(int $questionId): void
    {
        $question = $this->project->questions()->findOrFail($questionId);

        if ($question->status !== QuestionStatus::Open) {
            return;
        }

        $question->update(['status' => QuestionStatus::Discarded]);

        app(EventRecorder::class)->record(
            $this->project,
            'question.discarded',
            ['question_id' => $questionId],
            $question->execution,
            'you'
        );

        // Reviewer-escalated questions resume the execution once none are open.
        if ($question->execution_id) {
            $execution = $question->execution;
            if ($execution && $execution->questions()->open()->count() === 0) {
                app(\App\Core\Workflow\WorkflowEngine::class)->resumeAfterClarification($execution);
            }

            return;
        }

        if ($this->project->openQuestions()->count() === 0) {
            Cache::put("architect-turn:{$this->project->id}", 'thinking', now()->addMinutes(15));
            RunArchitectTurn::dispatch($this->project->id, null)
                ->onConnection('harness')
                ->onQueue('harness');
        }
    }

    public ?string $commitComment = null;
    public ?string $commitWarning = null;
    public string $buildProfile = 'attended';

    public function applyCommit(): void
    {
        $suggestion = $this->commitSuggestion;
        if (! $suggestion) return;

        try {
            app(\App\Projects\Repositories\CommitService::class)->apply($suggestion);
        } catch (\RuntimeException $e) {
            $this->commitWarning = $e->getMessage();
        }
    }

    public function reworkCommit(): void
    {
        $suggestion = $this->commitSuggestion;
        if (! $suggestion) return;

        if (trim((string) $this->commitComment) === '') {
            $this->addError('commitComment', 'Say what to change — the comment becomes the revision brief.');
            return;
        }

        app(\App\Projects\Repositories\CommitService::class)->rework($suggestion, $this->commitComment);
        $this->commitComment = null;
    }

    public function toggleArchive(): void
    {
        $this->project->update(['archived_at' => $this->project->archived_at ? null : now()]);
        $this->redirectRoute('home', navigate: false);
    }

    public function renameProject(): void
    {
        $this->validate(['settingsName' => 'required|string|max:120']);
        $this->project->update(['name' => $this->settingsName]);
        session()->flash('settings_ok', 'Renamed');
    }

    public function toggleConfirmCommits(): void
    {
        $this->project->update(['confirm_commits' => ! $this->project->confirm_commits]);
    }

    /**
     * A failed/parked task the owner can recover (M14b). Surfaces the retry card
     * when the latest execution parked, or its task failed (including an
     * abandoned run like TEST-M12bis's T-014). Null when there's nothing to fix.
     *
     * @return array{key: string, title: string, reason: ?string, strategy: string}|null
     */
    public function getRetryableTaskProperty(): ?array
    {
        $exec = $this->latestExecution;
        if (! $exec) {
            return null;
        }

        $task = $exec->tasks()->first();
        if (! $task) {
            return null;
        }

        $parked = $exec->status === \App\Enums\ExecutionStatus::Parked;
        $failed = $task->status === \App\Enums\TaskStatus::Failed;
        if (! $parked && ! $failed) {
            return null;
        }

        // Don't compete with a live "Start build" affordance.
        if ($this->plannedTask !== null) {
            return null;
        }

        return [
            'key' => $task->task_key,
            'title' => $task->title,
            'reason' => $exec->meta['parked_reason'] ?? null,
            'strategy' => $task->strategy()->value,
        ];
    }

    /**
     * Recovery for a parked/failed task (M14b): regenerate a fresh brief and
     * relaunch the build, optionally escalating to the frontier Builder. Backs
     * the escalation menu's Retry / Select-stronger-Builder actions.
     */
    /** Transient banner shown when an action is blocked by an in-flight run. */
    public ?string $runNotice = null;

    /**
     * True while a run is actively executing (one heavy task at a time —
     * PHILOSOPHY §2). New build/retry actions must wait for it, with feedback.
     */
    public function hasActiveRun(): bool
    {
        return $this->project->executions()
            ->where('status', \App\Enums\ExecutionStatus::Running)
            ->exists();
    }

    public function retryTask(string $taskKey, bool $escalate = false): void
    {
        if ($this->hasActiveRun()) {
            $this->runNotice = 'A run is already in progress — wait for it to finish (or pause it) before retrying.';

            return;
        }
        $this->runNotice = null;

        $task = $this->project->tasks()->where('task_key', $taskKey)->latest('id')->first();
        if ($task === null) {
            return;
        }

        \App\Jobs\RunTaskRetry::dispatch(
            $this->project->id,
            $taskKey,
            $escalate,
            in_array($this->buildProfile, ['attended', 'overnight', 'full_auto'], true) ? $this->buildProfile : 'attended',
        )
            ->onConnection('harness')
            ->onQueue('harness');

        $this->project->update(['status' => \App\Enums\ProjectStatus::Working, 'last_activity_at' => now()]);
    }

    /**
     * Opt-in actor rights (M14b): set the Architect's repository-access tier.
     * Guards the gated Commands tier server-side (not just in the UI) so it can
     * never be granted before a sandbox exists.
     */
    public function setCapabilityLevel(string $level): void
    {
        $capability = \App\Enums\CapabilityLevel::tryFrom($level);
        if ($capability === null || ! $capability->selectable()) {
            return;
        }

        $this->project->update(['capability_level' => $capability]);
        session()->flash('settings_ok', 'Repository access updated');
    }

    public function togglePushAfterMerge(): void
    {
        $current = Setting::get('git.push_after_merge', false);
        Setting::put('git.push_after_merge', ! $current);
    }

    /**
     * execution id → session index: the session whose closing plan preceded
     * the execution's start (timeline headers deep-link into the chat).
     */
    private function executionSessionMap(array $sessions): array
    {
        $map = [];
        foreach ($this->project->executions()->orderBy('id')->get() as $execution) {
            $index = count($sessions) - 1; // default: current session
            foreach ($sessions as $i => $session) {
                if ($session['closed'] && $session['endedAt'] && $session['endedAt']->lte($execution->created_at)) {
                    $index = min($i + 1, count($sessions) - 1);
                }
            }
            $map[$execution->id] = $index;
        }

        return $map;
    }

    public function getThinkingProperty(): bool
    {
        return Cache::has("architect-turn:{$this->project->id}");
    }

    public function getThinkingLabelProperty(): string
    {
        return Cache::get("architect-turn:{$this->project->id}") === 'planning'
            ? 'architect is writing the plan…'
            : 'architect is thinking…';
    }

    /**
     * The plan-approval gate is open when the LAST message is an Architect
     * turn that claimed consensus with zero open questions. Anything after it
     * (a new user message, the plan's system message) closes the moment.
     */
    public function getConsensusPendingProperty(): bool
    {
        $last = $this->project->consensusMessages()->orderByDesc('id')->first();

        return $last !== null
            && $last->role === \App\Enums\MessageRole::Architect
            && ($last->meta['consensusClaimed'] ?? false) === true
            && $this->project->openQuestions()->count() === 0;
    }

    public function approvePlan(): void
    {
        if (! $this->consensusPending || $this->thinking) {
            return;
        }

        Cache::put("architect-turn:{$this->project->id}", 'planning', now()->addMinutes(15));
        $this->project->update(['status' => \App\Enums\ProjectStatus::Working, 'last_activity_at' => now()]);

        \App\Jobs\RunPlanDraft::dispatch($this->project->id)
            ->onConnection('harness')
            ->onQueue('harness');
    }

    public function getLatestExecutionProperty(): ?\App\Models\Execution
    {
        return $this->project->executions()->latest('id')->first();
    }

    /**
     * The Builder badge for the latest execution (M14b): which Builder its task
     * uses, and whether a frontier build was downgraded to local for budget.
     *
     * @return array{label: string, downgraded: bool}|null
     */
    public function getBuilderBadgeProperty(): ?array
    {
        $exec = $this->latestExecution;
        if (! $exec) {
            return null;
        }
        $task = $exec->tasks()->first();
        if (! $task) {
            return null;
        }

        $downgraded = \App\Models\Event::where('execution_id', $exec->id)
            ->where('name', 'build.builder_downgraded')
            ->exists();
        $label = $task->strategy() === \App\Enums\ImplementationStrategy::Frontier
            ? ($downgraded ? 'Frontier → Local' : 'Frontier')
            : 'Local';

        return ['label' => $label, 'downgraded' => $downgraded];
    }

    public function getPipelineProperty(): array
    {
        $exec = $this->latestExecution;
        if (!$exec) {
            return ['nodes' => [], 'blocker' => 'Idle — no run in progress'];
        }

        $nodes = $exec->nodes->map(fn($n) => [
            'id' => $n->id,
            'key' => $n->id,
            'label' => $n->type,
            'status' => $n->status->value,
        ])->values()->toArray();

        if ($this->project->openQuestions()->exists()) {
            return ['nodes' => $nodes, 'blocker' => 'Waiting for you: answer the Architect\'s question'];
        }

        if ($this->openApproval) {
            return ['nodes' => $nodes, 'blocker' => 'Waiting for you: approval requested (' . $this->openApproval->type->value . ')'];
        }

        if ($exec->status === \App\Enums\ExecutionStatus::Parked) {
            $reason = $exec->meta['parked_reason'] ?? 'unknown';
            return ['nodes' => $nodes, 'blocker' => "Parked: {$reason}"];
        }

        $failedNode = $exec->nodes->first(fn($n) => $n->status === \App\Enums\NodeStatus::Failed);
        if ($failedNode) {
            return ['nodes' => $nodes, 'blocker' => "Failed at {$failedNode->type}"];
        }

        $runningNode = $exec->nodes->first(fn($n) => $n->status === \App\Enums\NodeStatus::Running);
        if ($runningNode) {
            return ['nodes' => $nodes, 'blocker' => "Working: {$runningNode->type}"];
        }

        return ['nodes' => $nodes, 'blocker' => 'Idle — waiting for next step'];
    }

    public function getPlannedTaskProperty(): ?array
    {
        // A pending plan approval supersedes an older written plan: offering
        // Start build from the stale brief while a re-scoped plan awaits
        // approval is the wrong-brief trap (owner-reported).
        if ($this->consensusPending) {
            return null;
        }

        $lastSystem = $this->project->consensusMessages()
            ->where('role', \App\Enums\MessageRole::System)
            ->orderByDesc('id')
            ->first();

        if (!$lastSystem || ($lastSystem->meta['planWritten'] ?? false) !== true) {
            return null;
        }

        $taskId = $lastSystem->meta['firstTaskId'] ?? null;
        if (!$taskId) {
            return null;
        }

        $activeExecution = $this->project->executions()
            ->whereIn('status', [\App\Enums\ExecutionStatus::Running, \App\Enums\ExecutionStatus::NeedsYou])
            ->exists();
        if ($activeExecution) {
            return null;
        }

        if ($this->project->tasks()->where('task_key', $taskId)->where('status', \App\Enums\TaskStatus::Approved)->exists()) {
            return null;
        }

        $memoryStore = app(\App\Projects\Memory\MemoryStore::class);
        $briefPath = "tasks/{$taskId}/task.md";
        $brief = $memoryStore->read($this->project, $briefPath);
        $title = $taskId;
        if ($brief) {
            if (preg_match('/^# (.+)$/m', $brief, $matches)) {
                $title = trim($matches[1]);
            }
        }

        return ['key' => $taskId, 'title' => $title];
    }

    /**
     * The stored agreed-plan text the Architect wrote at plan approval
     * (roadmap.md, else architecture.md, else the raw plan_draft.md). Shown
     * verbatim in the Overview/Roadmap "Agreed plan" accordion — no summary,
     * no LLM call, just the source of truth from project memory.
     */
    public function getPlanTextProperty(): ?string
    {
        $store = app(\App\Projects\Memory\MemoryStore::class);
        foreach (['roadmap.md', 'architecture.md', 'plan_draft.md'] as $doc) {
            $text = $store->read($this->project, $doc);
            if ($text !== null && trim($text) !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Overview summary: the agreed goal/why from project memory. Prefers
     * architecture.md, falls back to plan_draft.md. NEVER roadmap.md — the
     * Overview must not duplicate the Roadmap task list (T-58).
     */
    public function getProjectSummaryTextProperty(): ?string
    {
        $store = app(\App\Projects\Memory\MemoryStore::class);
        foreach (['architecture.md', 'plan_draft.md'] as $doc) {
            $text = $store->read($this->project, $doc);
            if ($text !== null && trim($text) !== '') {
                return $text;
            }
        }
        return null;
    }

    /**
     * Agreed specs: answered questions (question → answer) from consensus.
     */
    public function getAgreedSpecsProperty(): \Illuminate\Support\Collection
    {
        return $this->project->questions()
            ->where('status', \App\Enums\QuestionStatus::Answered)
            ->orderBy('answered_at')
            ->get();
    }

    public function getOpenApprovalProperty(): ?\App\Models\Approval
    {
        return $this->project->openApprovals()->first();
    }

    public function getCommitSuggestionProperty(): ?\App\Models\CommitSuggestion
    {
        $exec = $this->latestExecution;
        if (!$exec) {
            return null;
        }
        return \App\Models\CommitSuggestion::where('execution_id', $exec->id)->where('status', 'suggested')->first();
    }

    public function startBuild(): void
    {
        if ($this->plannedTask === null) {
            return;
        }

        if ($this->hasActiveRun()) {
            $this->runNotice = 'A run is already in progress — wait for it to finish (or pause it) before starting another.';

            return;
        }
        $this->runNotice = null;

        app(EventRecorder::class)->record(
            $this->project,
            'task.delegated',
            ['task_key' => $this->plannedTask['key']],
            null,
            'you'
        );

        \App\Core\Workflow\ImplementFeatureWorkflow::startForTask(
            $this->project,
            $this->plannedTask['key'],
            $this->plannedTask['title'],
            in_array($this->buildProfile, ['attended', 'overnight', 'full_auto'], true) ? $this->buildProfile : 'attended',
        );
    }

    /**
     * Switch the autonomy profile mid-flight (M13). Applies to the latest
     * execution so the running/auto-advanced chain and future tasks pick it up
     * (attended ↔ overnight ↔ full_auto). Also sets the default for the next
     * Start build.
     */
    public function switchProfile(string $profile): void
    {
        if (! in_array($profile, ['attended', 'overnight', 'full_auto'], true)) {
            return;
        }

        $this->buildProfile = $profile;

        $execution = $this->project->executions()->latest('id')->first();
        if ($execution) {
            $execution->update(['profile' => $profile]);
            app(EventRecorder::class)->record(
                $this->project,
                'profile.switched',
                ['profile' => $profile],
                $execution,
                'you'
            );
        }
    }

    public function resumeParked(): void
    {
        $exec = $this->latestExecution;
        if ($exec && $exec->status === \App\Enums\ExecutionStatus::Parked) {
            app(\App\Core\Workflow\WorkflowEngine::class)->resumeParked($exec);
        }
    }

    public function abandonParked(): void
    {
        $exec = $this->latestExecution;
        if ($exec && $exec->status === \App\Enums\ExecutionStatus::Parked) {
            app(\App\Core\Workflow\WorkflowEngine::class)->abandonParked($exec);
        }
    }

    public function approveApproval(): void
    {
        $approval = $this->openApproval;
        if (!$approval) {
            return;
        }

        app(\App\Core\Workflow\WorkflowEngine::class)->resolveApproval(
            $approval,
            true,
            $this->gateComment
        );
        $this->resetGateState();
    }

    public function rejectApproval(): void
    {
        $approval = $this->openApproval;
        if (!$approval) {
            return;
        }

        if ($approval->type !== ApprovalType::MilestoneMerge
            && trim($this->gateComment ?? '') === '') {
            $this->addError('gateComment', 'Say why — the comment becomes the revision brief.');
            return;
        }

        app(\App\Core\Workflow\WorkflowEngine::class)->resolveApproval(
            $approval,
            false,
            $this->gateComment
        );
        $this->resetGateState();
    }

    /** Clear the gate modal's transient state after a resolution. */
    private function resetGateState(): void
    {
        $this->gateComment = null;
        $this->showMilestoneDiff = false;
        $this->milestoneDiff = null;
        unset($this->openApproval);
    }

    /**
     * Milestone merge gate — "Not yet, keep it ready" (M16-A). Defers the merge:
     * the branch/worktree stay intact and it reappears as a "merge later"
     * affordance. Never the silent idle dead-end the owner hit before.
     */
    public function deferMilestone(): void
    {
        $approval = $this->openApproval;
        if (! $approval || $approval->type !== ApprovalType::MilestoneMerge) {
            return;
        }

        app(\App\Core\Workflow\WorkflowEngine::class)->deferMilestoneGate($approval);
        $this->resetGateState();
    }

    /**
     * Milestone merge gate — "Send back to the Architect" (M16-A, finding #5).
     * The owner's reason becomes ONE keyed fix-task; it rebuilds and re-reviews.
     * A reason is required — that's the whole point of this path.
     */
    public function requestGateChanges(): void
    {
        $approval = $this->openApproval;
        if (! $approval || $approval->type !== ApprovalType::MilestoneMerge) {
            return;
        }

        if (trim($this->gateComment ?? '') === '') {
            $this->addError('gateComment', 'Say what needs changing — it becomes the Architect’s fix brief.');

            return;
        }

        app(\App\Core\Workflow\WorkflowEngine::class)->requestMilestoneGateChanges($approval, $this->gateComment);
        $this->resetGateState();
    }

    /**
     * Show/hide the milestone's cumulative diff inside the gate (M16-A "view
     * diff"). Loaded on demand — the recap is frozen in the payload, but the
     * full diff is read live from the still-present worktree.
     */
    public function toggleMilestoneDiff(): void
    {
        $this->showMilestoneDiff = ! $this->showMilestoneDiff;

        if ($this->showMilestoneDiff && $this->milestoneDiff === null) {
            $approval = $this->openApproval;
            $milestone = $approval
                ? \App\Models\Milestone::find($approval->payload['milestone_id'] ?? 0)
                : null;
            $this->milestoneDiff = $milestone
                ? app(\App\Projects\Repositories\MilestoneDiff::class)->cumulative($milestone)
                : '';
        }
    }

    /** Merge a previously deferred milestone gate (M16-A "merge later"). */
    public function mergeDeferred(int $approvalId): void
    {
        $approval = $this->project->approvals()->whereKey($approvalId)->first();
        if (! $approval) {
            return;
        }

        app(\App\Core\Workflow\WorkflowEngine::class)->mergeDeferredMilestoneGate($approval);
        unset($this->deferredMilestoneGates);
    }

    /** @return \Illuminate\Support\Collection<int, \App\Models\Approval> */
    public function getDeferredMilestoneGatesProperty(): \Illuminate\Support\Collection
    {
        return $this->project->deferredMilestoneGates()->get();
    }

    public function selectEvent(int $eventId): void
    {
        $this->selectedEventId = $this->selectedEventId === $eventId ? null : $eventId;
    }

    public function getSelectedEventDetailProperty(): ?array
    {
        if ($this->selectedEventId === null) {
            return null;
        }

        $event = $this->project->events()->find($this->selectedEventId);
        if (!$event) {
            return null;
        }

        $node = null;
        if ($event->execution_id && str_contains($event->name, '.')) {
            $type = explode('.', $event->name)[0];
            $node = \App\Models\Node::where('execution_id', $event->execution_id)
                ->where('type', $type)
                ->orderByDesc('id')
                ->first();
        }

        return ['event' => $event, 'node' => $node];
    }

    public function getRecentConsensusProperty(): \Illuminate\Support\Collection
    {
        return $this->project->consensusMessages()
            ->orderByDesc('id')
            ->limit(10)
            ->get();
    }

    public function getUsageStatsProperty(): array
    {
        $byRole = \App\Models\UsageRecord::where('project_id', $this->project->id)
            ->selectRaw('role, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, SUM(cost_usd) as cost_usd')
            ->groupBy('role')
            ->get();

        $total = \App\Models\UsageRecord::where('project_id', $this->project->id)
            ->selectRaw('SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, SUM(cost_usd) as cost_usd')
            ->first();

        return [
            'by_role' => $byRole,
            'total' => $total,
        ];
    }

    public function getExecutionCountsProperty(): array
    {
        return $this->project->executions()
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    private bool $roadmapSynced = false;

    public function getRoadmapProperty(): array
    {
        if (!$this->roadmapSynced) {
            \App\Projects\Roadmap\RoadmapSync::for($this->project)->sync();
            $this->roadmapSynced = true;
        }

        $milestones = \App\Models\Milestone::where('project_id', $this->project->id)
            ->with('tasks')
            ->orderBy('position')
            ->get();

        $result = [];
        foreach ($milestones as $m) {
            $tasks = [];
            foreach ($m->tasks as $t) {
                $tasks[] = [
                    'id' => $t->id,
                    'key' => $t->task_key,
                    'title' => $t->title,
                    'status' => \App\Projects\Roadmap\RoadmapSync::effectiveStatus($t),
                    'description' => $t->description,
                ];
            }
            $result[] = [
                'id' => $m->id,
                'key' => $m->milestone_key,
                'title' => $m->title,
                'summary' => $m->summary,
                'status' => $m->deriveStatus(),
                'tasks' => $tasks,
            ];
        }

        return $result;
    }

    public function getMilestoneMetricsProperty(): array
    {
        if (!$this->roadmapSynced) {
            \App\Projects\Roadmap\RoadmapSync::for($this->project)->sync();
            $this->roadmapSynced = true;
        }

        $milestones = \App\Models\Milestone::where('project_id', $this->project->id)
            ->with('tasks')
            ->orderBy('position')
            ->get();

        $allTasks = $milestones->flatMap->tasks;
        $taskMetricsMap = \App\Projects\Metrics\MilestoneMetrics::forTasks($allTasks);

        $result = [];
        foreach ($milestones as $m) {
            $tasks = [];
            foreach ($m->tasks as $t) {
                $tasks[] = [
                    'key' => $t->task_key,
                    'title' => $t->title,
                    'metrics' => $taskMetricsMap[$t->id],
                ];
            }
            $result[] = [
                'key' => $m->milestone_key,
                'title' => $m->title,
                'status' => $m->deriveStatus(),
                'metrics' => \App\Projects\Metrics\MilestoneMetrics::forMilestone($m),
                'tasks' => $tasks,
            ];
        }

        return $result;
    }

    public function getRecentRoadmapChangesProperty(): \Illuminate\Support\Collection
    {
        return \App\Models\RoadmapEvent::where('project_id', $this->project->id)
            ->orderByDesc('id')
            ->limit(8)
            ->get();
    }

    public function getExecutionsProperty(): \Illuminate\Support\Collection
    {
        return $this->project->executions()->orderByDesc('id')->get();
    }

    public function getExchangesProperty(): array
    {
        $executions = $this->executions;
        if ($executions->isEmpty()) {
            return ['execution' => null, 'usage' => [], 'rows' => []];
        }

        $execution = $this->selectedExecutionId
            ? $executions->firstWhere('id', $this->selectedExecutionId)
            : $executions->first();

        if (!$execution) {
            return ['execution' => null, 'usage' => [], 'rows' => []];
        }

        return [
            'execution' => $execution,
            'usage' => ExchangeTrace::usageFor($execution),
            'rows' => ExchangeTrace::for($execution),
        ];
    }

    public function inspectNode(int $nodeId): void
    {
        $this->inspectedNodeId = $this->inspectedNodeId === $nodeId ? null : $nodeId;
    }

    public function getInspectedNodeProperty(): ?Node
    {
        if ($this->inspectedNodeId === null) {
            return null;
        }
        $node = Node::find($this->inspectedNodeId);
        if (!$node) {
            return null;
        }
        // Guard against cross-project IDs
        if (!$this->project->executions()->pluck('id')->contains($node->execution_id)) {
            return null;
        }
        return $node;
    }

    #[On('timeline-bump')]
    public function bumpTimeline(): void {}

    public function render()
    {
        $messages = $this->project->consensusMessages()->orderBy('id')->get();
        $questionsByMessage = $this->project->questions()->get()->groupBy('consensus_message_id');
        $openCount = $this->project->openQuestions()->count();

        $sessions = [];
        $currentSessionMessages = collect();
        foreach ($messages as $message) {
            $currentSessionMessages->push($message);
            $isDelimiter = $message->role === \App\Enums\MessageRole::System && ($message->meta['planWritten'] ?? false) === true;
            if ($isDelimiter) {
                $sessions[] = [
                    'messages' => $currentSessionMessages,
                    'closed' => true,
                    'endedAt' => $message->created_at,
                ];
                $currentSessionMessages = collect();
            }
        }
        if ($currentSessionMessages->isNotEmpty()) {
            $sessions[] = [
                'messages' => $currentSessionMessages,
                'closed' => false,
                'endedAt' => null,
            ];
        }

        $timelineEvents = $this->project->events()->orderByDesc('id')->limit(50)->get();
        $timelineGroups = [];
        $groupOrder = [];
        foreach ($timelineEvents as $event) {
            $key = $event->execution_id ?? 'consensus';
            if (!isset($timelineGroups[$key])) {
                $timelineGroups[$key] = collect();
                $groupOrder[] = $key;
            }
            $timelineGroups[$key]->push($event);
        }

        $latestExecId = $this->latestExecution?->id;
        $orderedTimelineGroups = [];
        foreach ($groupOrder as $key) {
            $events = $timelineGroups[$key];
            $label = 'consensus';
            $is_current = false;

            if ($key !== 'consensus') {
                $is_current = ($key == $latestExecId);
                $exec = \App\Models\Execution::with('tasks.milestone')->find($key);
                $task = $exec?->tasks->first();
                if ($task) {
                    $mKey = $task->milestone?->milestone_key ?? 'M?';
                    $label = "{$mKey} · {$task->task_key}";
                }
                if ($label === 'consensus') {
                    $label = "execution #{$key}";
                }
            }

            $orderedTimelineGroups[] = [
                'key' => $key,
                'events' => $events,
                'label' => $label,
                'is_current' => $is_current,
            ];
        }

        $executionSessionMap = $this->executionSessionMap($sessions);

        return view('livewire.project-workspace', [
            'sessions' => $sessions,
            'questionsByMessage' => $questionsByMessage,
            'openCount' => $openCount,
            'timelineGroups' => $orderedTimelineGroups,
            'executionSessionMap' => $executionSessionMap,
            'workflows' => \App\Models\Workflow::orderBy('is_builtin', 'desc')->orderBy('name')->get(),
        ])->title("Majordom — {$this->project->name}");
    }
}
