<?php

namespace Tests\Feature;

use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use App\Models\ConsensusMessage;
use App\Models\Execution;
use App\Models\Approval;
use App\Models\CommitSuggestion;
use App\Models\Node;
use App\Enums\MessageRole;
use App\Enums\ExecutionStatus;
use App\Enums\ApprovalType;
use App\Enums\ApprovalStatus;
use App\Enums\NodeStatus;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    config(['majordom.memory_root' => sys_get_temp_dir()]);
});

test('start-build card shows when plan exists and no execution', function () {
    $project = Project::factory()->create();
    ConsensusMessage::create([
        'project_id' => $project->id,
        'role' => MessageRole::System,
        'content' => '',
        'meta' => ['planWritten' => true, 'firstTaskId' => 'T-001'],
    ]);

    app(MemoryStore::class)->write($project, 'tasks/T-001/task.md', "# Add guard\n\nSome details.");

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Start build')
        ->assertSee('T-001')
        ->assertSee('Add guard');
});

test('startBuild creates execution and task', function () {
    $project = Project::factory()->create();
    ConsensusMessage::create([
        'project_id' => $project->id,
        'role' => MessageRole::System,
        'content' => '',
        'meta' => ['planWritten' => true, 'firstTaskId' => 'T-001'],
    ]);
    app(MemoryStore::class)->write($project, 'tasks/T-001/task.md', "# Add guard");

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('startBuild');

    expect($project->executions()->count())->toBe(1);
    Queue::assertPushed(\App\Core\Workflow\Nodes\DelegateNode::class);
});

test('start-build card hidden while execution is running', function () {
    $project = Project::factory()->create();
    ConsensusMessage::create([
        'project_id' => $project->id,
        'role' => MessageRole::System,
        'content' => '',
        'meta' => ['planWritten' => true, 'firstTaskId' => 'T-001'],
    ]);
    app(MemoryStore::class)->write($project, 'tasks/T-001/task.md', "# Add guard");

    Execution::create([
        'project_id' => $project->id,
        'status' => ExecutionStatus::Running,
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertDontSee('Start build');
});

test('review gate card renders from open approval', function () {
    $project = Project::factory()->create();
    $execution = Execution::create(['project_id' => $project->id, 'status' => ExecutionStatus::NeedsYou]);
    Node::create(['execution_id' => $execution->id, 'type' => 'review', 'status' => NodeStatus::WaitingHuman]);

    Approval::create([
        'project_id' => $project->id,
        'execution_id' => $execution->id,
        'type' => ApprovalType::Review,
        'status' => ApprovalStatus::Open,
        'title' => 'Review PR #1',
        'payload' => [
            'diff' => "diff --git a/test.php b/test.php\n--- a/test.php\n+++ b/test.php\n@@ -1,2 +1,2 @@\n-old\n+new",
            'verdict' => ['summary' => 'Looks good', 'comments' => []],
            'testsPassed' => true,
            'filesChanged' => ['test.php'],
        ],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Review PR #1')
        ->assertSee('Looks good')
        ->assertSee('tests ✓')
        ->assertSee('+new');
});

test('rejectReview without comment errors and approval stays open', function () {
    $project = Project::factory()->create();
    $execution = Execution::create(['project_id' => $project->id, 'status' => ExecutionStatus::NeedsYou]);
    $approval = Approval::create([
        'project_id' => $project->id,
        'execution_id' => $execution->id,
        'type' => ApprovalType::Review,
        'status' => ApprovalStatus::Open,
        'title' => 'Review PR #1',
        'payload' => ['diff' => '', 'verdict' => ['summary' => ''], 'testsPassed' => null, 'filesChanged' => []],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('rejectReview')
        ->assertHasErrors(['gateComment' => 'Say why — the comment becomes the revision brief.']);

    $approval->refresh();
    expect($approval->status)->toBe(ApprovalStatus::Open);
});

test('approveReview resolves approval and moves execution', function () {
    $project = Project::factory()->create();
    $execution = Execution::create(['project_id' => $project->id, 'status' => ExecutionStatus::NeedsYou]);
    $node = \App\Models\Node::create([
        'execution_id' => $execution->id,
        'type' => 'review',
        'status' => \App\Enums\NodeStatus::WaitingHuman,
    ]);
    $approval = Approval::create([
        'project_id' => $project->id,
        'execution_id' => $execution->id,
        'type' => ApprovalType::Review,
        'status' => ApprovalStatus::Open,
        'title' => 'Review PR #1',
        'payload' => ['node_id' => $node->id, 'diff' => '', 'verdict' => ['summary' => ''], 'testsPassed' => null, 'filesChanged' => []],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('gateComment', 'Looks fine')
        ->call('approveReview');

    $approval->refresh();
    expect($approval->status)->toBe(ApprovalStatus::Granted);
    $execution->refresh();
    // No further pending nodes → the engine completes the execution.
    expect($execution->status)->toBe(ExecutionStatus::Completed)
        ->and($node->fresh()->output['comment'])->toBe('Looks fine');
});

test('commit-suggestion card shows message and branch', function () {
    $project = Project::factory()->create();
    $execution = Execution::create(['project_id' => $project->id, 'status' => ExecutionStatus::Completed]);
    CommitSuggestion::create([
        'project_id' => $project->id,
        'execution_id' => $execution->id,
        'status' => 'suggested',
        'branch' => 'feat/add-guard',
        'message' => 'feat: add guard clause',
        'diff' => '',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('feat: add guard clause')
        ->assertSee('feat/add-guard');
});

test('start-build card hides while a plan approval is pending', function () {
    config(['majordom.memory_root' => sys_get_temp_dir().'/majordom-ui-'.uniqid()]);
    $project = Project::factory()->create();
    // Older written plan…
    ConsensusMessage::create([
        'project_id' => $project->id, 'role' => 'system',
        'content' => 'memory written', 'meta' => ['planWritten' => true, 'firstTaskId' => 'T-001'],
    ]);
    app(MemoryStore::class)->write($project, 'tasks/T-001/task.md', '# Old scope');
    // …then a NEW consensus claim awaiting approval.
    ConsensusMessage::create([
        'project_id' => $project->id, 'role' => 'architect',
        'content' => 'Re-scoped.', 'meta' => ['consensusClaimed' => true],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Plan approval')
        ->assertDontSee('Start build');
});
