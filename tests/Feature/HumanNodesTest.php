<?php

use App\Core\Workflow\ImplementFeatureWorkflow;
use App\Core\Workflow\Nodes\HumanTaskNode;
use App\Core\Workflow\Nodes\HumanReviewNode;
use App\Core\Workflow\WorkflowEngine;
use App\Enums\ApprovalType;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Models\Approval;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function setupMemoryRoot(): string
{
    $root = sys_get_temp_dir().'/majordom-test-'.uniqid();
    Config::set('majordom.memory_root', $root);
    return $root;
}

function createExecutionWithTask(array $taskAttrs = [], array $projectAttrs = []): array
{
    $project = Project::factory()->create($projectAttrs);
    $task = Task::factory()->create(array_merge([
        'project_id' => $project->id,
        'task_key' => 'feat-1',
        'branch' => 'feat/branch-1',
        'status' => \App\Enums\TaskStatus::Pending,
        'revision' => 1,
    ], $taskAttrs));
    $execution = Execution::factory()->create(['project_id' => $project->id]);
    $execution->tasks()->save($task);
    $node = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'human_task']);
    return [$execution, $task, $node, $project];
}

test('HumanTaskNode waits with HumanTask approval carrying worktree and brief', function () {
    setupMemoryRoot();
    $repoDir = sys_get_temp_dir().'/majordom-noderepo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);
    [$execution, $task, $node, $project] = createExecutionWithTask([], ['repo_path' => $repoDir]);

    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Build the feature.");

    Process::fake([
        "'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n"),
        "'git' 'worktree' 'add'*" => Process::result(output: 'ok'),
    ]);

    $job = new HumanTaskNode($node->id);
    $job->handle();

    $node->refresh();
    expect($node->status)->toBe(NodeStatus::WaitingHuman);
    expect($node->output['worktree'])->not->toBeNull();

    $approval = Approval::open()->first();
    expect($approval->type)->toBe(ApprovalType::HumanTask);
    expect($approval->payload['worktree'])->not->toBeNull();
    expect($approval->payload['brief'])->toContain('Build the feature.');
});

test('HumanTaskNode rejection parks execution', function () {
    setupMemoryRoot();
    $repoDir = sys_get_temp_dir().'/majordom-noderepo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);
    [$execution, $task, $node, $project] = createExecutionWithTask([], ['repo_path' => $repoDir]);

    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Build it.");

    Process::fake([
        "'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n"),
        "'git' 'worktree' 'add'*" => Process::result(output: 'ok'),
    ]);

    $job = new HumanTaskNode($node->id);
    $job->handle();

    $approval = Approval::open()->first();
    app(WorkflowEngine::class)->resolveApproval($approval, false, 'I give up');

    $execution->refresh();
    expect($execution->status)->toBe(ExecutionStatus::Parked);
    expect($execution->meta['parked_reason'] ?? '')->toContain('I give up');
});

test('HumanReviewNode waits with Review approval and diff payload', function () {
    setupMemoryRoot();
    $repoDir = sys_get_temp_dir().'/majordom-noderepo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);
    [$execution, $task, $node, $project] = createExecutionWithTask([], ['repo_path' => $repoDir]);

    // Create a fake completed build node with diff
    $buildNode = Node::factory()->create([
        'execution_id' => $execution->id,
        'type' => 'build',
        'status' => NodeStatus::Completed,
        'output' => ['diff' => 'diff --git a/test.txt b/test.txt', 'filesChanged' => ['test.txt']],
    ]);

    $reviewNode = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'human_review']);

    $job = new HumanReviewNode($reviewNode->id);
    $job->handle();

    $reviewNode->refresh();
    expect($reviewNode->status)->toBe(NodeStatus::WaitingHuman);

    $approval = Approval::open()->first();
    expect($approval->type)->toBe(ApprovalType::Review);
    expect($approval->payload['diff'])->toContain('diff --git');
});

test('HumanReviewNode grant advances chain', function () {
    setupMemoryRoot();
    $repoDir = sys_get_temp_dir().'/majordom-noderepo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);
    [$execution, $task, $node, $project] = createExecutionWithTask([], ['repo_path' => $repoDir]);

    $buildNode = Node::factory()->create([
        'execution_id' => $execution->id,
        'type' => 'build',
        'status' => NodeStatus::Completed,
        'output' => ['diff' => 'diff --git', 'filesChanged' => []],
    ]);

    $reviewNode = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'human_review']);
    $nextNode = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'commit_suggestion', 'status' => NodeStatus::Pending]);

    $job = new HumanReviewNode($reviewNode->id);
    $job->handle();

    $approval = Approval::open()->first();
    app(WorkflowEngine::class)->resolveApproval($approval, true);

    $nextNode->refresh();
    expect($nextNode->status)->toBe(NodeStatus::Running);
});

test('human_task and human_review are registered in nodeMap', function () {
    $map = ImplementFeatureWorkflow::nodeMap();
    expect($map)->toHaveKey('human_task');
    expect($map)->toHaveKey('human_review');
    expect($map['human_task'])->toBe(\App\Core\Workflow\Nodes\HumanTaskNode::class);
    expect($map['human_review'])->toBe(\App\Core\Workflow\Nodes\HumanReviewNode::class);
});
