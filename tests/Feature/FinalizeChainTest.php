<?php

use App\Core\Workflow\ImplementFeatureWorkflow;
use App\Core\Workflow\Nodes\FinalizeNode;
use App\Enums\TaskStatus;
use App\Models\CommitSuggestion;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Milestone;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Repositories\CommitService;
use App\Projects\Repositories\WorktreeManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('default chain ends in finalize, not commit_suggestion', function () {
    $project = Project::factory()->create(['confirm_commits' => false]);

    $chain = ImplementFeatureWorkflow::chainFor($project);

    expect($chain)->toContain('finalize')->not->toContain('commit_suggestion');
});

test('confirm_commits swaps finalize for a commit_suggestion checkpoint', function () {
    $project = Project::factory()->create(['confirm_commits' => true]);

    $chain = ImplementFeatureWorkflow::chainFor($project);

    expect($chain)->toContain('commit_suggestion')->not->toContain('finalize');
});

test('FinalizeNode marks the task Approved', function () {
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id]);
    $task = Task::factory()->create(['project_id' => $project->id, 'execution_id' => $execution->id, 'status' => TaskStatus::Reviewing]);
    $node = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'finalize']);

    (new FinalizeNode($node->id))->handle();

    expect($task->fresh()->status)->toBe(TaskStatus::Approved);
});

test('completion of a finalize-flow execution auto-advances the milestone chain', function () {
    Queue::fake();
    setupMemoryRoot();

    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'status' => \App\Enums\ExecutionStatus::Running, 'profile' => 'attended']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1']);
    $t1 = Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'execution_id' => $execution->id,
        'task_key' => 'T-001', 'position' => 1, 'status' => TaskStatus::Approved,
    ]);
    Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'execution_id' => null,
        'task_key' => 'T-002', 'title' => 'Next', 'position' => 2, 'status' => TaskStatus::Pending,
    ]);
    app(\App\Projects\Memory\MemoryStore::class)->write($project, 'tasks/T-002/task.md', '# brief');

    // A finalize-flow execution: one completed finalize node, no commit_suggestion.
    Node::factory()->create(['execution_id' => $execution->id, 'type' => 'finalize', 'status' => \App\Enums\NodeStatus::Completed]);

    app(\App\Core\Workflow\WorkflowEngine::class)->advance($execution->fresh());

    expect($execution->fresh()->status)->toBe(\App\Enums\ExecutionStatus::Completed);
    expect(Event::where('name', 'task.autoadvanced')->where('project_id', $project->id)->exists())->toBeTrue();
});

test('apply on a milestone task is a checkpoint — marks done + advances, never merges to main', function () {
    Queue::fake();
    Process::fake(); // any git call here would be a bug — assert none ran
    setupMemoryRoot();

    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'profile' => 'attended']);
    $milestone = Milestone::factory()->create(['project_id' => $project->id, 'milestone_key' => 'M1']);
    $task = Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'execution_id' => $execution->id,
        'task_key' => 'T-001', 'position' => 1, 'status' => TaskStatus::Reviewing,
        'worktree_path' => null,
    ]);
    // a next task so advance has somewhere to go
    Task::factory()->create([
        'project_id' => $project->id, 'milestone_id' => $milestone->id, 'execution_id' => null,
        'task_key' => 'T-002', 'title' => 'Next', 'position' => 2, 'status' => TaskStatus::Pending,
    ]);
    app(\App\Projects\Memory\MemoryStore::class)->write($project, 'tasks/T-002/task.md', '# brief'); // decompose no-op

    $suggestion = CommitSuggestion::create([
        'project_id' => $project->id, 'execution_id' => $execution->id, 'task_id' => $task->id,
        'message' => 'x', 'diff' => 'd', 'branch' => 'majordom/M1', 'status' => 'suggested',
    ]);

    app(CommitService::class)->apply($suggestion);

    expect($task->fresh()->status)->toBe(TaskStatus::Approved);
    expect(Event::where('name', 'checkpoint.approved')->where('project_id', $project->id)->exists())->toBeTrue();
    // NEVER promoted to main for a milestone task:
    Process::assertNotRan(fn ($p) => is_array($p->command) && in_array('merge', $p->command, true));
    // advanced to T-002
    expect(Event::where('name', 'task.autoadvanced')->exists())->toBeTrue();
});
