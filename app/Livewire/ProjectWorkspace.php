<?php

namespace App\Livewire;

use App\Agents\Architect\ArchitectService;
use App\Enums\QuestionStatus;
use App\Jobs\RunArchitectTurn;
use App\Models\Project;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class ProjectWorkspace extends Component
{
    public Project $project;
    public string $draft = '';
    public array $answerDrafts = [];
    /** Free-text answers; when non-empty they win over a picked option. */
    public array $customDrafts = [];

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

    public function render()
    {
        $messages = $this->project->consensusMessages()->orderBy('id')->get();
        $questionsByMessage = $this->project->questions()->get()->groupBy('consensus_message_id');
        $openCount = $this->project->openQuestions()->count();

        return view('livewire.project-workspace', [
            'messages' => $messages,
            'questionsByMessage' => $questionsByMessage,
            'openCount' => $openCount,
        ])->title("Majordom — {$this->project->name}");
    }
}
