<?php

namespace App\Livewire;

use App\Models\Approval;
use App\Models\CommitSuggestion;
use App\Models\Project;
use App\Models\Question;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Majordom — Inbox')]
class Inbox extends Component
{
    public ?int $projectFilter = null;

    public function render()
    {
        $questions = Question::open()
            ->whereRelation('project', 'archived_at', null)
            ->get()
            ->map(fn($q) => [
                'type' => 'question',
                'label' => 'QUESTION',
                'title' => Str::limit($q->text ?? '', 120),
                'project' => $q->project,
                'at' => $q->created_at,
                'action' => 'Answer ›',
            ]);

        $approvals = Approval::open()
            ->whereRelation('project', 'archived_at', null)
            ->get()
            ->map(fn($a) => [
                'type' => 'arbitrate',
                'label' => 'ARBITRATE',
                'title' => Str::limit($a->title ?? '', 120),
                'project' => $a->project,
                'at' => $a->created_at,
                'action' => 'Review ›',
            ]);

        $commits = CommitSuggestion::where('status', 'suggested')
            ->whereRelation('project', 'archived_at', null)
            ->get()
            ->map(fn($c) => [
                'type' => 'commit_ready',
                'label' => 'COMMIT READY',
                'title' => Str::limit(explode("\n", $c->message ?? '')[0], 120),
                'project' => $c->project,
                'at' => $c->created_at,
                'action' => 'Commit ›',
            ]);

        $items = Collection::make([$questions, $approvals, $commits])
            ->flatten(1)
            ->when($this->projectFilter, fn($col) => $col->where('project.id', $this->projectFilter))
            ->sortByDesc(fn($item) => $item['at'])
            ->values();

        $projects = Project::whereNull('archived_at')
            ->where(function ($q) {
                $q->whereHas('questions', fn($sub) => $sub->open())
                  ->orWhereHas('approvals', fn($sub) => $sub->open())
                  ->orWhereHas('commitSuggestions', fn($sub) => $sub->where('status', 'suggested'));
            })
            ->orderBy('name')
            ->get();

        return view('livewire.inbox', [
            'items' => $items,
            'projects' => $projects,
            'count' => $items->count(),
        ]);
    }

    public static function openCount(): int
    {
        return Question::open()->whereRelation('project', 'archived_at', null)->count()
            + Approval::open()->whereRelation('project', 'archived_at', null)->count()
            + CommitSuggestion::where('status', 'suggested')->whereRelation('project', 'archived_at', null)->count();
    }
}
