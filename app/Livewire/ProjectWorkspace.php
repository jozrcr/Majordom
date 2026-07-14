<?php

namespace App\Livewire;

use App\Agents\Architect\ArchitectService;
use App\Core\Events\EventRecorder;
use App\Enums\QuestionStatus;
use App\Jobs\RunArchitectTurn;
use App\Models\Project;
use App\Projects\Exchanges\ExchangeTrace;
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

    public function mount(Project $project): void
    {
        $this->project = $project;
        $this->workflowId = $project->workflow_id;
        $this->normalizeTab();
    }

    public function updatedTab(): void
    {
        $this->normalizeTab();
    }

    private function normalizeTab(): void
    {
        if (!in_array($this->tab, ['chat', 'overview', 'stats', 'roadmap', 'exchanges'], true)) {
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

    public ?string $commitComment = null;
    public string $buildProfile = 'attended';

    public function applyCommit(): void
    {
        $suggestion = $this->commitSuggestion;
        if (! $suggestion) return;

        try {
            app(\App\Projects\Repositories\CommitService::class)->apply($suggestion);
        } catch (\RuntimeException $e) {
            $this->addError('commitComment', $e->getMessage());
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

    public function rejectCommit(): void
    {
        $suggestion = $this->commitSuggestion;
        if (! $suggestion) return;

        if (trim((string) $this->commitComment) === '') {
            $this->addError('commitComment', 'Say why — rejections need a reason.');
            return;
        }

        app(\App\Projects\Repositories\CommitService::class)->reject($suggestion, $this->commitComment);
        $this->commitComment = null;
    }

    public function toggleArchive(): void
    {
        $this->project->update(['archived_at' => $this->project->archived_at ? null : now()]);
        $this->redirectRoute('home', navigate: false);
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
            in_array($this->buildProfile, ['attended', 'overnight'], true) ? $this->buildProfile : 'attended',
        );
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
    }

    public function rejectApproval(): void
    {
        $approval = $this->openApproval;
        if (!$approval) {
            return;
        }

        if (trim($this->gateComment ?? '') === '') {
            $this->addError('gateComment', 'Say why — the comment becomes the revision brief.');
            return;
        }

        app(\App\Core\Workflow\WorkflowEngine::class)->resolveApproval(
            $approval,
            false,
            $this->gateComment
        );
        $this->gateComment = null;
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
        $orderedTimelineGroups = [];
        foreach ($groupOrder as $key) {
            $orderedTimelineGroups[] = [
                'key' => $key,
                'events' => $timelineGroups[$key],
            ];
        }

        return view('livewire.project-workspace', [
            'sessions' => $sessions,
            'questionsByMessage' => $questionsByMessage,
            'openCount' => $openCount,
            'timelineGroups' => $orderedTimelineGroups,
            'workflows' => \App\Models\Workflow::orderBy('is_builtin', 'desc')->orderBy('name')->get(),
        ])->title("Majordom — {$this->project->name}");
    }
}
