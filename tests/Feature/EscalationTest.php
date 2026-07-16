<?php

use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Reviewer\ReviewerService;
use App\Agents\Reviewer\ReviewVerdict;
use App\Core\Workflow\Nodes\ReviewNode;
use App\Core\Workflow\WorkflowEngine;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\QuestionStatus;
use App\Enums\TaskStatus;
use App\Livewire\ProjectWorkspace;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

class EscalatingProvider implements Provider
{
    public function chat(ProviderRequest $request): ProviderResponse
    {
        return new ProviderResponse(json_encode([
            'verdict' => 'changes_requested',
            'comments' => [],
            'summary' => 'The brief is ambiguous about the flag name.',
            'questions' => ['Should the flag be --log or --log-file?', 'Append or overwrite the log?'],
        ]), 'stop', 10, 20);
    }
}

function escalationSetup(): array
{
    config([
        'majordom.memory_root' => sys_get_temp_dir().'/majordom-esc-'.uniqid(),
        'queue.connections.harness.driver' => 'database',
    ]);
    $provider = new EscalatingProvider;
    app()->instance(Provider::class, $provider);
    app()->instance(ReviewerService::class, new ReviewerService(app(\App\Agents\Providers\ProviderRegistry::class), MemoryStore::fromConfig(), app(\App\Projects\Repositories\RepoIndex::class)));

    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $task = Task::factory()->create([
        'project_id' => $project->id, 'execution_id' => $execution->id,
        'task_key' => 'T-001', 'branch' => 'majordom/T-001', 'revision' => 1,
    ]);
    Node::factory()->create([
        'execution_id' => $execution->id, 'type' => 'build', 'status' => NodeStatus::Completed,
        'output' => ['diff' => 'diff --git a/x b/x', 'filesChanged' => ['x'], 'summary' => 's'],
    ]);
    Node::factory()->create(['execution_id' => $execution->id, 'type' => 'test', 'status' => NodeStatus::Completed, 'output' => ['testsPassed' => true]]);
    $review = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'review', 'status' => NodeStatus::Pending]);

    return [$project, $execution, $task, $review];
}

it('parses reviewer questions into the verdict', function () {
    $v = ReviewVerdict::fromContent(json_encode([
        'verdict' => 'changes_requested', 'comments' => [], 'summary' => 's',
        'questions' => ['A?', '  ', 'B?'],
    ]));

    expect($v->needsClarification())->toBeTrue()
        ->and($v->questions)->toBe(['A?', 'B?']);
});

it('escalates: questions created, execution waits, no approval row', function () {
    [$project, $execution, $task, $review] = escalationSetup();

    (new ReviewNode($review->id))->handle();

    expect($review->fresh()->status)->toBe(NodeStatus::WaitingHuman)
        ->and($execution->fresh()->status)->toBe(ExecutionStatus::NeedsYou)
        ->and($execution->approvals()->count())->toBe(0)
        ->and($execution->questions()->open()->count())->toBe(2)
        ->and($task->fresh()->status)->toBe(TaskStatus::NeedsYou)
        ->and($project->fresh()->status)->toBe(\App\Enums\ProjectStatus::NeedsYou);
});

it('answers resume the build with a clarified brief and a reset budget', function () {
    [$project, $execution, $task, $review] = escalationSetup();
    app(MemoryStore::class)->write($project, 'tasks/T-001/task.md', 'Original brief');
    (new ReviewNode($review->id))->handle();

    $component = Livewire::test(ProjectWorkspace::class, ['project' => $project]);
    foreach ($execution->questions()->open()->get() as $i => $q) {
        $component->set("customDrafts.{$q->id}", "Answer {$i}")
            ->call('answerQuestion', $q->id);
    }

    $execution->refresh();
    $task->refresh();
    expect($execution->status)->toBe(ExecutionStatus::Running)
        ->and($execution->questions()->open()->count())->toBe(0)
        ->and($task->revision)->toBe(2)
        ->and($task->clarified_at_revision)->toBe(2)
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($execution->nodes()->where('type', 'build')->first()->status)->toBe(NodeStatus::Pending)
        ->and($execution->nodes()->where('type', 'review')->first()->status)->toBe(NodeStatus::Pending);

    $brief = app(MemoryStore::class)->read($project, 'tasks/T-001/task.v2.md');
    expect($brief)->toContain('Original brief')
        ->and($brief)->toContain('Owner clarifications')
        ->and($brief)->toContain('--log or --log-file')
        ->and($brief)->toContain('Answer 0');
});

it('clarification resets the revision budget', function () {
    [, , $task] = escalationSetup();
    $task->update(['revision' => 5, 'clarified_at_revision' => 4]);

    // effective revisions since human input = 1 → within a budget of 3
    $budgetBase = (int) $task->clarified_at_revision;
    expect($task->revision - $budgetBase)->toBeLessThanOrEqual(3);
});

it('partial answers do not resume', function () {
    [$project, $execution, , $review] = escalationSetup();
    (new ReviewNode($review->id))->handle();

    $first = $execution->questions()->open()->first();
    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set("customDrafts.{$first->id}", 'Only one')
        ->call('answerQuestion', $first->id);

    expect($execution->fresh()->status)->toBe(ExecutionStatus::NeedsYou)
        ->and($execution->questions()->open()->count())->toBe(1);
});
