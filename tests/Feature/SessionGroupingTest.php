<?php

use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use App\Models\ConsensusMessage;
use App\Models\Event;
use App\Enums\MessageRole;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('sessions are grouped by planWritten delimiter', function () {
    Queue::fake();
    $project = Project::factory()->create();

    // Session 1
    ConsensusMessage::factory()->create(['project_id' => $project->id, 'role' => MessageRole::User, 'content' => 'First message']);
    ConsensusMessage::factory()->create(['project_id' => $project->id, 'role' => MessageRole::Architect, 'content' => 'First architect']);
    ConsensusMessage::factory()->create(['project_id' => $project->id, 'role' => MessageRole::System, 'content' => 'Plan written', 'meta' => ['planWritten' => true]]);

    // Session 2
    ConsensusMessage::factory()->create(['project_id' => $project->id, 'role' => MessageRole::User, 'content' => 'Second message']);
    ConsensusMessage::factory()->create(['project_id' => $project->id, 'role' => MessageRole::System, 'content' => 'Plan written 2', 'meta' => ['planWritten' => true]]);

    // Session 3 (current)
    ConsensusMessage::factory()->create(['project_id' => $project->id, 'role' => MessageRole::User, 'content' => 'Third message']);

    $html = Livewire::test(ProjectWorkspace::class, ['project' => $project])->html();

    // Assert two closed sessions
    $count = preg_match_all('/session \d+ ·/', $html);
    expect($count)->toBe(2);

    // Current session message visible
    $this->assertStringContainsString('Third message', $html);

    // Closed session message in DOM
    $this->assertStringContainsString('First message', $html);
});

test('timeline groups events by execution_id', function () {
    Queue::fake();
    $project = Project::factory()->create();

    $e1 = \App\Models\Execution::factory()->create(['project_id' => $project->id]);
    $e2 = \App\Models\Execution::factory()->create(['project_id' => $project->id]);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => null, 'name' => 'consensus.event']);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => $e1->id, 'name' => 'exec1.event']);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => $e2->id, 'name' => 'exec2.event']);

    $html = Livewire::test(ProjectWorkspace::class, ['project' => $project])->html();

    $this->assertStringContainsString('consensus', $html);
    $this->assertStringContainsString('execution #1', $html);
    $this->assertStringContainsString('execution #2', $html);
});

test('message blocks carry id attributes', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $msg = ConsensusMessage::factory()->create(['project_id' => $project->id, 'role' => MessageRole::User, 'content' => 'Test msg']);

    $html = Livewire::test(ProjectWorkspace::class, ['project' => $project])->html();

    $this->assertStringContainsString("id=\"msg-{$msg->id}\"", $html);
});
