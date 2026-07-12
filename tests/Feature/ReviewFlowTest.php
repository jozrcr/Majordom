<?php

use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Reviewer\ReviewerService;
use App\Agents\Reviewer\ReviewVerdict;
use App\Core\Workflow\Nodes\CommitSuggestionNode;
use App\Core\Workflow\Nodes\ReviewNode;
use App\Enums\ApprovalType;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\TaskStatus;
use App\Models\CommitSuggestion;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

class ScriptedReviewProvider implements Provider
{
    public array $requests = [];

    public function __construct(public string $response) {}

    public function chat(ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;

        return new ProviderResponse($this->response, 'stop', 10, 20);
    }
}

function reviewSetup(string $providerResponse): array
{
    config([
        'majordom.memory_root' => sys_get_temp_dir().'/majordom-review-'.uniqid(),
        'queue.connections.harness.driver' => 'sync',
    ]);

    $provider = new ScriptedReviewProvider($providerResponse);
    app()->instance(Provider::class, $provider);
    app()->instance(ReviewerService::class, new ReviewerService($provider, MemoryStore::fromConfig()));

    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'execution_id' => $execution->id,
        'task_key' => 'T-001',
        'title' => 'Add divide guard',
        'branch' => 'majordom/T-001',
        'revision' => 1,
    ]);
    Node::factory()->create([
        'execution_id' => $execution->id,
        'type' => 'build',
        'status' => NodeStatus::Completed,
        'output' => ['diff' => "diff --git a/x b/x\n+guard", 'filesChanged' => ['x.py'], 'summary' => 'Added guard.'],
    ]);
    Node::factory()->create([
        'execution_id' => $execution->id,
        'type' => 'test',
        'status' => NodeStatus::Completed,
        'output' => ['testsPassed' => true],
    ]);
    $reviewNode = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'review', 'status' => NodeStatus::Pending]);

    return [$project, $execution, $task, $reviewNode, $provider];
}

it('parses an approved verdict and a malformed one bias-fails', function () {
    $ok = ReviewVerdict::fromContent(json_encode([
        'verdict' => 'approved',
        'comments' => [['file' => 'a.php', 'comment' => 'nit']],
        'summary' => 'Looks right.',
    ]));
    expect($ok->approved)->toBeTrue()->and($ok->comments[0]['file'])->toBe('a.php');

    $bad = ReviewVerdict::fromContent('not json');
    expect($bad->approved)->toBeFalse()
        ->and($bad->summary)->toContain('malformed');
});

it('approved review parks behind the human gate with diff + verdict payload', function () {
    [$project, $execution, $task, $reviewNode, $provider] = reviewSetup(json_encode([
        'verdict' => 'approved', 'comments' => [], 'summary' => 'Meets the criteria.',
    ]));

    (new ReviewNode($reviewNode->id))->handle();

    $execution->refresh();
    expect($execution->status)->toBe(ExecutionStatus::NeedsYou)
        ->and($task->fresh()->status)->toBe(TaskStatus::NeedsYou);

    $approval = $execution->approvals()->first();
    expect($approval->type)->toBe(ApprovalType::Review)
        ->and($approval->title)->toContain('T-001')
        ->and($approval->payload['diff'])->toContain('+guard')
        ->and($approval->payload['verdict']['summary'])->toBe('Meets the criteria.')
        ->and($approval->payload['testsPassed'])->toBeTrue();

    // The reviewer got the brief, the handoff-less context, and the diff.
    $sent = $provider->requests[0]->messages[1]['content'];
    expect($sent)->toContain('+guard')->and($sent)->toContain('Automated tests PASSED');
});

it('changes_requested writes the revision brief and parks', function () {
    [$project, $execution, $task, $reviewNode] = reviewSetup(json_encode([
        'verdict' => 'changes_requested',
        'comments' => [['file' => 'x.py', 'comment' => 'Handle negative b.']],
        'summary' => 'Edge case missing.',
    ]));

    app(MemoryStore::class)->write($project, 'tasks/T-001/task.md', 'Original brief');

    (new ReviewNode($reviewNode->id))->handle();

    $execution->refresh();
    expect($execution->status)->toBe(ExecutionStatus::Parked)
        ->and($execution->meta['parked_reason'])->toContain('revision brief')
        ->and($task->fresh()->revision)->toBe(2)
        ->and($task->fresh()->status)->toBe(TaskStatus::Failed);

    $brief = app(MemoryStore::class)->read($project, 'tasks/T-001/task.v2.md');
    expect($brief)->toContain('Original brief')
        ->and($brief)->toContain('Handle negative b.')
        ->and($brief)->toContain('Edge case missing.');
});

it('review gate grant runs commit suggestion and completes the execution', function () {
    [$project, $execution, $task, $reviewNode] = reviewSetup(json_encode([
        'verdict' => 'approved', 'comments' => [], 'summary' => 'Ship it.',
    ]));
    Node::factory()->create(['execution_id' => $execution->id, 'type' => 'commit_suggestion', 'status' => NodeStatus::Pending]);

    (new ReviewNode($reviewNode->id))->handle();
    $approval = $execution->approvals()->first();

    app(\App\Core\Workflow\WorkflowEngine::class)->resolveApproval($approval, granted: true);

    $execution->refresh();
    expect($execution->status)->toBe(ExecutionStatus::Completed)
        ->and($task->fresh()->status)->toBe(TaskStatus::Approved);

    $suggestion = CommitSuggestion::first();
    expect($suggestion)->not->toBeNull()
        ->and($suggestion->message)->toContain('feat(T-001): Add divide guard')
        ->and($suggestion->message)->toContain('Ship it.')
        ->and($suggestion->diff)->toContain('+guard')
        ->and($suggestion->branch)->toBe('majordom/T-001')
        ->and($suggestion->status)->toBe('suggested');
});

it('commit suggestion node fails cleanly without a diff', function () {
    config(['queue.connections.harness.driver' => 'sync']);
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    Task::factory()->create(['project_id' => $project->id, 'execution_id' => $execution->id]);
    $node = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'commit_suggestion', 'status' => NodeStatus::Pending]);

    (new CommitSuggestionNode($node->id))->handle();

    // No build output at all → message built from empty parts, still created.
    expect(CommitSuggestion::count())->toBe(1);
});
