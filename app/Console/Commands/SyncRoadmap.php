<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Projects\Roadmap\RoadmapSync;
use Illuminate\Console\Command;

class SyncRoadmap extends Command
{
    protected $signature = 'majordom:sync-roadmap {project?}';
    protected $description = 'Sync ROADMAP.md to the database for one or all projects.';

    public function handle(): int
    {
        $projects = $this->argument('project')
            ? Project::where('slug', $this->argument('project'))->orWhere('id', $this->argument('project'))->get()
            : Project::all();

        foreach ($projects as $project) {
            RoadmapSync::for($project)->sync();
            $milestones = \App\Models\Milestone::where('project_id', $project->id)->count();
            $tasks = \App\Models\Task::where('project_id', $project->id)->count();
            $this->info("Synced {$project->name}: {$milestones} milestones, {$tasks} tasks.");
        }

        return Command::SUCCESS;
    }
}
