<?php

use App\Enums\ExecutionStatus;
use App\Enums\QuestionStatus;
use App\Livewire\ProjectWorkspace;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Question;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(fn () => Queue::fake());

test('discarding a consensus question stops it blocking + re-prompts the architect', function () {
    $project = Project::factory()->create();
    $q = Question::create(['project_id' => $project->id, 'text' => '你好?', 'status' => QuestionStatus::Open]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('discardQuestion', $q->id);

    expect($q->fresh()->status)->toBe(QuestionStatus::Discarded);
    expect($project->openQuestions()->count())->toBe(0);
    expect(Event::where('name', 'question.discarded')->where('project_id', $project->id)->exists())->toBeTrue();
    Queue::assertPushed(\App\Jobs\RunArchitectTurn::class); // architect re-prompted
});

test('discarding the last escalated question resumes the execution', function () {
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::NeedsYou]);
    // a task + a review node in WaitingHuman so resumeAfterClarification has something to re-arm
    $task = \App\Models\Task::factory()->create(['project_id' => $project->id, 'execution_id' => $execution->id, 'task_key' => 'T-001', 'revision' => 1]);
    \App\Models\Node::factory()->create(['execution_id' => $execution->id, 'type' => 'review', 'status' => \App\Enums\NodeStatus::WaitingHuman]);
    app(\App\Projects\Memory\MemoryStore::class); setupMemoryRoot();
    app(\App\Projects\Memory\MemoryStore::class)->write($project, 'tasks/T-001/task.md', '# brief');

    $q = Question::create(['project_id' => $project->id, 'execution_id' => $execution->id, 'text' => 'garbled', 'status' => QuestionStatus::Open]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('discardQuestion', $q->id);

    expect($q->fresh()->status)->toBe(QuestionStatus::Discarded);
    expect($execution->fresh()->status)->toBe(ExecutionStatus::Running); // resumed
});

test('discarding an already-resolved question is a no-op', function () {
    $project = Project::factory()->create();
    $q = Question::create(['project_id' => $project->id, 'text' => 'x', 'status' => QuestionStatus::Answered, 'answer' => 'y']);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('discardQuestion', $q->id);

    expect($q->fresh()->status)->toBe(QuestionStatus::Answered);
});
