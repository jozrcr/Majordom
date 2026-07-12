<?php

namespace App\Livewire;

use App\Agents\Architect\ArchitectService;
use App\Core\Events\EventRecorder;
use App\Enums\QuestionStatus;
use App\Jobs\RunArchitectTurn;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\On;
use Livewire\Component;

class ProjectWorkspace extends Component
{
    public Project $project;
    public string $draft = '';
    public array $answerDrafts = [];
    /** Free-text answers; when non-empty they win over a picked option. */
    public array $customDrafts = [];
    public ?string $gateComment = null;

    public function mount(Project $project): void
    {
        $this->project = $project;
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

        if ($this->project->openQuestions()->count() === 0) {
            Cache::put("architect-turn:{$this->project->id}", 'thinking', now()->addMinutes(15));
            RunArchitectTurn::dispatch($this->project->id, null)
                ->onConnection('harness')
                ->onQueue('harness');
        }
    }

    public function getThinkingProperty(): bool
    {
        return Cache::has("architect-turn:{$this->project->id}");
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

    public function getReviewApprovalProperty(): ?\App\Models\Approval
    {
        return $this->project->openApprovals()
            ->where('type', \App\Enums\ApprovalType::Review)
            ->first();
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

        app(\App\Core\Workflow\ImplementFeatureWorkflow::class)->startForTask(
            $this->project,
            $this->plannedTask['key'],
            $this->plannedTask['title']
        );
    }

    public function approveReview(): void
    {
        $approval = $this->reviewApproval;
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

    public function rejectReview(): void
    {
        $approval = $this->reviewApproval;
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

    #[On('timeline-bump')]
    public function bumpTimeline(): void {}

    public function render()
    {
        $messages = $this->project->consensusMessages()->orderBy('id')->get();
        $questionsByMessage = $this->project->questions()->get()->groupBy('consensus_message_id');
        $openCount = $this->project->openQuestions()->count();
        $timeline = $this->project->events()->orderByDesc('id')->limit(50)->get();

        return view('livewire.project-workspace', [
            'messages' => $messages,
            'questionsByMessage' => $questionsByMessage,
            'openCount' => $openCount,
            'timeline' => $timeline,
        ])->title("Majordom — {$this->project->name}");
    }
}
