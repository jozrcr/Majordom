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

    /**
     * Post-plan steering (M14): once a plan exists, free chat is replaced by
     * defined-action modes so every interaction has a clear intent. `chatMode`
     * is null (buttons shown), 'add_context', or 'redefine'.
     */
    public ?string $chatMode = null;

    public function getPlanExistsProperty(): bool
    {
        return $this->project->consensusMessages()
            ->where('role', \App\Enums\MessageRole::System)
            ->get()
            ->contains(fn ($m) => ($m->meta['planWritten'] ?? false) === true);
    }

    public function setChatMode(string $mode): void
    {
        if (! in_array($mode, ['add_context', 'redefine'], true)) {
            return;
        }
        $this->chatMode = $mode;
        $this->draft = '';
    }

    public function cancelChatMode(): void
    {
        $this->chatMode = null;
        $this->draft = '';
    }

    public function submitChatMode(): void
    {
        $this->validate(['draft' => 'required|string|max:8000']);
        $mode = $this->chatMode;
        $text = $this->draft;
        $this->chatMode = null;
        $this->draft = '';

        if ($mode === 'add_context') {
            // Fast + deterministic (no LLM) — folds into project memory.
            app(ArchitectService::class)->addContext($this->project, $text);

            return;
        }

        if ($mode === 'redefine') {
            Cache::put("architect-turn:{$this->project->id}", 'planning', now()->addMinutes(15));
            $this->project->update(['status' => \App\Enums\ProjectStatus::Working, 'last_activity_at' => now()]);
            \App\Jobs\RunPlanRedefine::dispatch($this->project->id, $text)
                ->onConnection('harness')
                ->onQueue('harness');
        }
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

    /**
     * Never-stall recovery (M14a): the last Architect turn produced no question
     * and did not reach consensus, and no plan exists yet — the pre-plan loop is
     * waiting on direction. The workspace surfaces a one-click nudge instead of
     * a silent dead end (e2e#3 "stalls after Q&A").
     */
    public function getArchitectStalledProperty(): bool
    {
        if ($this->thinking || $this->planExists) {
            return false;
        }

        $last = $this->project->consensusMessages()->orderByDesc('id')->first();

        return $last !== null
            && $last->role === \App\Enums\MessageRole::Architect
            && ($last->meta['consensusClaimed'] ?? false) === false
            && $this->project->openQuestions()->count() === 0;
    }

    /**
     * Re-run the Architect turn with a corrective system note so it either
     * raises its open questions or reaches consensus, instead of leaving the
     * owner to compose the right prompt by hand.
     */
    public function nudgeArchitect(): void
    {
        if (! $this->architectStalled) {
            return;
        }

        $this->project->consensusMessages()->create([
            'role' => \App\Enums\MessageRole::System,
            'content' => 'The previous turn ended without a question or a consensus decision. Continue now: either raise the specific open questions you still need answered, or — if you already have enough to define the scope — set consensus_reached to true and restate the agreed scope. If you need to see particular files to decide, name them explicitly.',
            'meta' => ['nudge' => true],
        ]);

        Cache::put("architect-turn:{$this->project->id}", 'thinking', now()->addMinutes(15));
        $this->project->update(['status' => \App\Enums\ProjectStatus::Working, 'last_activity_at' => now()]);

        RunArchitectTurn::dispatch($this->project->id, null)
            ->onConnection('harness')
            ->onQueue('harness');
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
        $this->gateComment = null;
        unset($this->openApproval);
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
        $this->gateComment = null;
        unset($this->openApproval);
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
