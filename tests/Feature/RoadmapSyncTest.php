<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Task;
use App\Projects\Roadmap\RoadmapSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RoadmapSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_parses_fixture_and_upserts(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectory(dirname($mdPath));
        File::put($mdPath, <<<MD
# Majordom — Roadmap

## M1 — Foundations
Auth and dashboard.

- [x] T-01 — Token auth
- [ ] T-02 — Dashboard
MD
        );

        RoadmapSync::for($project)->sync();

        $this->assertDatabaseCount('milestones', 1);
        $this->assertDatabaseHas('milestones', ['project_id' => $project->id, 'milestone_key' => 'M1']);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseHas('tasks', ['project_id' => $project->id, 'task_key' => 'T-01', 'declared_status' => 'done']);
        $this->assertDatabaseHas('tasks', ['project_id' => $project->id, 'task_key' => 'T-02', 'declared_status' => 'todo']);
    }

    public function test_sync_is_idempotent(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectory(dirname($mdPath));
        File::put($mdPath, "## M1 — Test\n- [ ] T-01 — Task\n");

        RoadmapSync::for($project)->sync();
        $this->assertDatabaseCount('roadmap_events', 1); // task_added

        RoadmapSync::for($project)->sync();
        $this->assertDatabaseCount('roadmap_events', 1); // unchanged
    }

    public function test_status_change_emits_event(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectory(dirname($mdPath));
        File::put($mdPath, "## M1 — Test\n- [ ] T-01 — Task\n");

        RoadmapSync::for($project)->sync();
        File::put($mdPath, "## M1 — Test\n- [x] T-01 — Task\n");

        RoadmapSync::for($project)->sync();
        $this->assertDatabaseHas('roadmap_events', [
            'project_id' => $project->id,
            'type' => 'task_status_changed',
            'subject_key' => 'T-01',
            'detail' => 'todo → done',
        ]);
    }

    public function test_live_db_status_overrides_md_upward(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectory(dirname($mdPath));
        File::put($mdPath, "## M1 — Test\n- [ ] T-01 — Task\n");

        RoadmapSync::for($project)->sync();
        $task = Task::where('project_id', $project->id)->first();
        $task->update(['status' => \App\Enums\TaskStatus::Building]);

        $effective = RoadmapSync::effectiveStatus($task);
        $this->assertEquals('ongoing', $effective);
    }

    public function test_missing_file_is_noop(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/missing-repo']);
        RoadmapSync::for($project)->sync();
        $this->assertDatabaseCount('milestones', 0);
    }

    public function test_artisan_command_runs(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo', 'slug' => 'test-slug']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectory(dirname($mdPath));
        File::put($mdPath, "## M1 — Test\n- [ ] T-01 — Task\n");

        $this->artisan('majordom:sync-roadmap', ['project' => 'test-slug'])
            ->assertSuccessful();
    }
}
