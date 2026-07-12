<?php

use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use App\Models\Message;
use App\Models\Event;
use App\Enums\MessageRole;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('sessions are grouped by planWritten delimiter', function () {
    Queue::fake();
    $project = Project::factory()->create();

    // Session 1
    Message::factory()->create(['project_id' => $project->id, 'role' => MessageRole::User, 'content' => 'First message']);
    Message::factory()->create(['project_id' => $project->id, 'role' => MessageRole::Architect, 'content' => 'First architect']);
    Message::factory()->create(['project_id' => $project->id, 'role' => MessageRole::System, 'content' => 'Plan written', 'meta' => ['planWritten' => true]]);

    // Session 2
    Message::factory()->create(['project_id' => $project->id, 'role' => MessageRole::User, 'content' => 'Second message']);
    Message::factory()->create(['project_id' => $project->id, 'role' => MessageRole::System, 'content' => 'Plan written 2', 'meta' => ['planWritten' => true]]);

    // Session 3 (current)
    Message::factory()->create(['project_id' => $project->id, 'role' => MessageRole::User, 'content' => 'Third message']);

    $html = Livewire::test(ProjectWorkspace::class, ['project' => $project])->html();

    // Assert two closed sessions
    $count = preg_match_all('/session ·/', $html);
    expect($count)->toBe(2);

    // Current session message visible
    $this->assertStringContainsString('Third message', $html);

    // Closed session message in DOM
    $this->assertStringContainsString('First message', $html);
});

test('timeline groups events by execution_id', function () {
    Queue::fake();
    $project = Project::factory()->create();

    Event::factory()->create(['project_id' => $project->id, 'execution_id' => null, 'name' => 'consensus.event']);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => 1, 'name' => 'exec1.event']);
    Event::factory()->create(['project_id' => $project->id, 'execution_id' => 2, 'name' => 'exec2.event']);

    $html = Livewire::test(ProjectWorkspace::class, ['project' => $project])->html();

    $this->assertStringContainsString('consensus', $html);
    $this->assertStringContainsString('execution #1', $html);
    $this->assertStringContainsString('execution #2', $html);
});

test('message blocks carry id attributes', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $msg = Message::factory()->create(['project_id' => $project->id, 'role' => MessageRole::User, 'content' => 'Test msg']);

    $html = Livewire::test(ProjectWorkspace::class, ['project' => $project])->html();

    $this->assertStringContainsString("id=\"msg-{$msg->id}\"", $html);
});
