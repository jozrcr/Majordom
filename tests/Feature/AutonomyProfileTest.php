<?php

use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Reviewer\ReviewerService;
use App\Core\Workflow\ImplementFeatureWorkflow;
use App\Core\Workflow\Nodes\ReviewNode;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\TaskStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\UsageRecord;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

class ApprovingProvider implements Provider
{
    public function chat(ProviderRequest $request): ProviderResponse
    {
        return new ProviderResponse(
            json_encode(['verdict' => 'approved', 'comments' => [], 'summary' => 'Fine.']),
            'stop', 10, 20,
        );
    }
}

function autonomySetup(string $profile): array
{
    config([
        'majordom.memory_root' => sys_get_temp_dir().'/majordom-auto-'.uniqid(),
        'queue.connections.harness.driver' => 'database',
    ]);
    $provider = new ApprovingProvider;
    app()->instance(Provider::class, $provider);
    app()->instance(ReviewerService::class, new ReviewerService(app(\App\Agents\Providers\ProviderRegistry::class), MemoryStore::fromConfig()));

    $project = Project::factory()->create();
    $execution = Execution::factory()->create([
        'project_id' => $project->id, 'status' => ExecutionStatus::Running, 'profile' => $profile,
    ]);
    $task = Task::factory()->create([
        'project_id' => $project->id, 'execution_id' => $execution->id,
        'task_key' => 'T-001', 'branch' => 'majordom/T-001',
    ]);
    Node::factory()->create([
        'execution_id' => $execution->id, 'type' => 'build', 'status' => NodeStatus::Completed,
        'output' => ['diff' => 'diff --git a/x b/x', 'filesChanged' => ['x'], 'summary' => 's'],
    ]);
    $review = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'review', 'status' => NodeStatus::Pending]);

    return [$project, $execution, $task, $review];
}

it('overnight: an approved review auto-proceeds — no approval row, no needs_you', function () {
    Queue::fake();
    [, $execution, $task, $review] = autonomySetup('overnight');

    (new ReviewNode($review->id))->handle();

    expect($review->fresh()->status)->toBe(NodeStatus::Completed)
        ->and($review->fresh()->output['autoApproved'])->toBeTrue()
        ->and($task->fresh()->status)->toBe(TaskStatus::Approved)
        ->and($execution->fresh()->status)->toBe(ExecutionStatus::Completed) // no nodes left → chain flowed through
        ->and($execution->approvals()->count())->toBe(0);
});

it('attended: the same approved review blocks at the human gate', function () {
    Queue::fake();
    [, $execution, , $review] = autonomySetup('attended');

    (new ReviewNode($review->id))->handle();

    expect($review->fresh()->status)->toBe(NodeStatus::WaitingHuman)
        ->and($execution->fresh()->status)->toBe(ExecutionStatus::NeedsYou)
        ->and($execution->approvals()->count())->toBe(1);
});

it('parks when the frontier spend cap is exceeded', function () {
    Queue::fake();
    [$project, $execution, , $review] = autonomySetup('overnight');
    $execution->update(['spend_cap_usd' => 0.10]);
    UsageRecord::create([
        'project_id' => $project->id, 'execution_id' => $execution->id,
        'role' => 'architect', 'model' => 'm', 'prompt_tokens' => 1, 'completion_tokens' => 1,
        'cost_usd' => 0.25,
    ]);

    (new ReviewNode($review->id))->handle();

    $execution->refresh();
    expect($execution->status)->toBe(ExecutionStatus::Parked)
        ->and($execution->meta['parked_reason'])->toContain('spend cap')
        ->and($review->fresh()->status)->toBe(NodeStatus::Failed)
        ->and($project->fresh()->status)->toBe(\App\Enums\ProjectStatus::NeedsYou);
});

it('startForTask stamps profile and the overnight cap', function () {
    Queue::fake();
    config(['majordom.workflow.overnight_spend_cap_usd' => 2.5]);
    $project = Project::factory()->create();
    app(MemoryStore::class); // noop, keeps parity with prod path

    $task = ImplementFeatureWorkflow::startForTask($project, 'T-009', 'X', 'overnight');

    $execution = $task->execution;
    expect($execution->profile)->toBe('overnight')
        ->and((float) $execution->spend_cap_usd)->toBe(2.5);

    $attended = ImplementFeatureWorkflow::startForTask($project, 'T-010', 'Y');
    expect($attended->execution->profile)->toBe('attended')
        ->and($attended->execution->spend_cap_usd)->toBeNull();
});

it('unknown profiles fall back to blocking', function () {
    $execution = Execution::factory()->create(['profile' => 'nonsense']);

    expect($execution->gateBehavior('review'))->toBe('block');
});
