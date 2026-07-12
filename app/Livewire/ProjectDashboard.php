<?php

namespace App\Livewire;

use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Majordom — Projects')]
class ProjectDashboard extends Component
{
    public ?string $name = null;
    public ?string $repoPath = null;
    public bool $showForm = false;
    public bool $showArchived = false;

    public function render()
    {
        $projects = Project::query()
            ->when(! $this->showArchived, fn ($q) => $q->whereNull('archived_at'))
            ->when($this->showArchived, fn ($q) => $q->whereNotNull('archived_at'))
            ->get()
            ->sortByDesc('last_activity_at')
            ->sortBy(function ($project) {
                return match ($project->status) {
                    ProjectStatus::NeedsYou => 0,
                    ProjectStatus::Working => 1,
                    ProjectStatus::Idle => 2,
                    ProjectStatus::Parked => 3,
                };
            })
            ->values();

        $counts = $projects->countBy(fn (Project $p) => $p->status->value);
        $summaryParts = [];
        foreach ([ProjectStatus::NeedsYou, ProjectStatus::Working, ProjectStatus::Idle, ProjectStatus::Parked] as $status) {
            if (($count = $counts->get($status->value, 0)) > 0) {
                $summaryParts[] = "{$count} {$status->label()}";
            }
        }
        $summary = implode(' · ', $summaryParts);

        return view('livewire.project-dashboard', [
            'projects' => $projects,
            'summary' => $summary,
            'archivedCount' => Project::whereNotNull('archived_at')->count(),
        ]);
    }

    public function createProject()
    {
        $this->validate([
            'name' => 'required|string|max:100',
            'repoPath' => 'required|string',
        ]);

        if (!is_dir($this->repoPath) || !is_dir($this->repoPath . '/.git')) {
            $this->addError('repoPath', 'Not a git repository.');
            return;
        }

        $slug = Str::slug($this->name);
        if (Project::where('slug', $slug)->exists()) {
            $this->addError('name', 'A project with this name already exists.');
            return;
        }

        Project::create([
            'name' => $this->name,
            'slug' => $slug,
            'repo_path' => $this->repoPath,
            'memory_path' => null,
            'status' => ProjectStatus::Idle,
            'last_activity_at' => now(),
        ]);

        $this->name = null;
        $this->repoPath = null;
        $this->showForm = false;
    }
}
