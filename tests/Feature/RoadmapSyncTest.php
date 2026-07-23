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
        File::ensureDirectoryExists(dirname($mdPath));
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
        File::ensureDirectoryExists(dirname($mdPath));
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
        File::ensureDirectoryExists(dirname($mdPath));
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
        File::ensureDirectoryExists(dirname($mdPath));
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
        File::ensureDirectoryExists(dirname($mdPath));
        File::put($mdPath, "## M1 — Test\n- [ ] T-01 — Task\n");

        $this->artisan('majordom:sync-roadmap', ['project' => 'test-slug'])
            ->assertSuccessful();
    }

    public function test_milestone_with_unfinished_task_is_not_done(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectoryExists(dirname($mdPath));
        // One done, one todo — a mix must read as ongoing, never done.
        File::put($mdPath, "## M1 — Mixed\n- [x] T-01 — A\n- [ ] T-02 — B\n");

        RoadmapSync::for($project)->sync();
        $milestone = \App\Models\Milestone::where('project_id', $project->id)->first();

        $this->assertSame('ongoing', $milestone->deriveStatus());

        // All done → done.
        File::put($mdPath, "## M1 — Mixed\n- [x] T-01 — A\n- [x] T-02 — B\n");
        RoadmapSync::for($project)->sync();
        $this->assertSame('done', $milestone->fresh()->deriveStatus());
    }

    public function test_em_dash_title_has_no_stray_bytes(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectoryExists(dirname($mdPath));
        File::put($mdPath, "## M1 — Project Skeleton & D-Bus Daemon\n- [ ] T-01 — Create repo structure\n");

        RoadmapSync::for($project)->sync();

        $milestone = \App\Models\Milestone::where('project_id', $project->id)->first();
        // The em-dash separator must be consumed whole (regex /u) — no leading
        // stray continuation bytes bleeding into the title.
        $this->assertSame('Project Skeleton & D-Bus Daemon', $milestone->title);
        $task = Task::where('project_id', $project->id)->first();
        $this->assertSame('Create repo structure', $task->title);
    }

    public function test_sync_parses_legacy_milestone_format(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectoryExists(dirname($mdPath));
        File::put($mdPath, "## Milestone 1: Foundations\nAuth and dashboard.\n- Some prose line.\n");

        RoadmapSync::for($project)->sync();

        $this->assertDatabaseCount('milestones', 1);
        $this->assertDatabaseHas('milestones', ['project_id' => $project->id, 'milestone_key' => 'M1']);
        $milestone = \App\Models\Milestone::where('project_id', $project->id)->first();
        $this->assertStringContainsString('Auth and dashboard.', $milestone->summary);
        $this->assertStringContainsString('Some prose line.', $milestone->summary);
    }

    public function test_sync_reads_description_from_memory(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectoryExists(dirname($mdPath));
        File::put($mdPath, "## M1 — Test\n- [ ] T-01 — Task\n");

        // Write task brief to memory
        app(\App\Projects\Memory\MemoryStore::class)->write($project, 'tasks/T-01/task.md', '# Task Brief\nDo something.');

        RoadmapSync::for($project)->sync();

        $task = Task::where('project_id', $project->id)->first();
        $this->assertNotNull($task->description);
        $this->assertStringContainsString('Task Brief', $task->description);
    }

    public function test_description_only_change_is_idempotent(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/test-repo']);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectoryExists(dirname($mdPath));
        File::put($mdPath, "## M1 — Test\n- [ ] T-01 — Task\n");

        RoadmapSync::for($project)->sync();
        $this->assertDatabaseCount('roadmap_events', 1); // task_added

        // Update description in memory
        app(\App\Projects\Memory\MemoryStore::class)->write($project, 'tasks/T-01/task.md', '# Updated Brief');
        RoadmapSync::for($project)->sync();

        // No new event should be created for description-only change
        $this->assertDatabaseCount('roadmap_events', 1);
    }

    /**
     * M16-D2 freeze: a re-sync from a revised roadmap that omits work must never
     * orphan or drop a BUILT task (the small-space-sim "T-007 forgotten" bug). Only
     * a not-started task may be removed; done/ongoing work is frozen in place.
     */
    public function test_sync_never_drops_built_work(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/freeze-repo-'.uniqid()]);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectoryExists(dirname($mdPath));
        File::put($mdPath, "## M1 — Test\n- [x] T-01 — Built\n- [ ] T-02 — Todo\n");
        RoadmapSync::for($project)->sync();
        $milestoneId = \App\Models\Milestone::where('project_id', $project->id)->first()->id;

        // A revision that omits BOTH prior tasks: the built one stays attached
        // (frozen), the not-started one is legitimately removed (orphaned).
        File::put($mdPath, "## M1 — Test\n- [ ] T-03 — New\n");
        RoadmapSync::for($project)->sync();

        $built = Task::where('project_id', $project->id)->where('task_key', 'T-01')->first();
        $this->assertNotNull($built);
        $this->assertSame($milestoneId, $built->milestone_id); // frozen, not orphaned

        $todo = Task::where('project_id', $project->id)->where('task_key', 'T-02')->first();
        $this->assertNull($todo->milestone_id); // not-started → removed

        $this->assertDatabaseHas('tasks', ['project_id' => $project->id, 'task_key' => 'T-03']);
    }

    /**
     * M16-D2: renderMarkdown is the inverse of parse — the canonical roadmap it
     * emits from the DB round-trips back to the same milestone/task keys and
     * effective statuses. Used to re-persist roadmap.md after a revision.
     */
    public function test_render_markdown_round_trips_keys_and_statuses(): void
    {
        $project = Project::factory()->create(['repo_path' => '/tmp/rt-repo-'.uniqid()]);
        $mdPath = $project->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectoryExists(dirname($mdPath));
        File::put($mdPath, "## M1 — Foundations\nAuth and dashboard.\n\n- [x] T-01 — Token auth\n- [ ] T-02 — Dashboard\n\n## M2 — Polish\n- [ ] T-03 — Theme\n");
        RoadmapSync::for($project)->sync();

        $rendered = RoadmapSync::for($project)->renderMarkdown();
        $this->assertStringContainsString('## M1 — Foundations', $rendered);
        $this->assertStringContainsString('- [x] T-01 — Token auth', $rendered);
        $this->assertStringContainsString('- [ ] T-02 — Dashboard', $rendered);
        $this->assertStringContainsString('## M2 — Polish', $rendered);

        // Round-trip: parse the rendered md into a FRESH project → same keys/statuses.
        $other = Project::factory()->create(['repo_path' => '/tmp/rt-repo2-'.uniqid()]);
        $otherPath = $other->repo_path . '/agents/ROADMAP.md';
        File::ensureDirectoryExists(dirname($otherPath));
        File::put($otherPath, $rendered);
        RoadmapSync::for($other)->sync();

        $this->assertSame(['M1', 'M2'], RoadmapSync::milestoneKeysIn($rendered));
        $this->assertDatabaseHas('tasks', ['project_id' => $other->id, 'task_key' => 'T-01', 'declared_status' => 'done']);
        $this->assertDatabaseHas('tasks', ['project_id' => $other->id, 'task_key' => 'T-02', 'declared_status' => 'todo']);
        $this->assertDatabaseHas('tasks', ['project_id' => $other->id, 'task_key' => 'T-03', 'declared_status' => 'todo']);
    }
}
