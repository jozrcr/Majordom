<?php

use App\Agents\Architect\ArchitectService;
use App\Enums\QuestionStatus;
use App\Jobs\RunArchitectTurn;
use App\Models\ConsensusMessage;
use App\Models\Project;
use App\Models\Question;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
});

test('page GET shows project name and returns 200', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    
    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertSee($project->name)
        ->assertStatus(200);
});

test('component renders existing messages', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    ConsensusMessage::create(['project_id' => $project->id, 'role' => 'user', 'content' => 'Hello']);
    ConsensusMessage::create(['project_id' => $project->id, 'role' => 'architect', 'content' => 'Hi there']);
    
    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Hello')
        ->assertSee('Hi there');
});

test('send with draft pushes job and clears draft', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    
    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->set('draft', 'Build a login page')
        ->call('send')
        ->assertSet('draft', '');
        
    Queue::assertPushed(RunArchitectTurn::class, function ($job) use ($project) {
        return $job->projectId === $project->id && $job->userMessage === 'Build a login page';
    });
});

test('send with empty draft fails validation', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    
    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->set('draft', '')
        ->call('send')
        ->assertHasErrors(['draft' => 'required']);
        
    Queue::assertNothingPushed();
});

test('answerQuestion records answer and pushes job if last open', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $msg = ConsensusMessage::create(['project_id' => $project->id, 'role' => 'architect', 'content' => 'Question?']);
    $question = Question::create([
        'project_id' => $project->id,
        'consensus_message_id' => $msg->id,
        'status' => QuestionStatus::Open,
        'text' => 'Choose?',
        'options' => ['Yes', 'No'],
    ]);
    
    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->set('answerDrafts.'.$question->id, 'Yes')
        ->call('answerQuestion', $question->id);
        
    $question->refresh();
    expect($question->status)->toBe(QuestionStatus::Answered);
    
    Queue::assertPushed(RunArchitectTurn::class, function ($job) use ($project) {
        return $job->projectId === $project->id && $job->userMessage === null;
    });
});

test('answerQuestion with empty answer fails validation', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $msg = ConsensusMessage::create(['project_id' => $project->id, 'role' => 'architect', 'content' => 'Question?']);
    $question = Question::create([
        'project_id' => $project->id,
        'consensus_message_id' => $msg->id,
        'status' => QuestionStatus::Open,
        'text' => 'Answer?',
    ]);
    
    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->set('answerDrafts.'.$question->id, '')
        ->call('answerQuestion', $question->id)
        ->assertHasErrors(["answer-{$question->id}"]);
        
    $question->refresh();
    expect($question->status)->toBe(QuestionStatus::Open);
});

test('open question card renders text and options, answered renders collapsed', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $msg = ConsensusMessage::create(['project_id' => $project->id, 'role' => 'architect', 'content' => 'Q']);
    $openQ = Question::create([
        'project_id' => $project->id,
        'consensus_message_id' => $msg->id,
        'status' => QuestionStatus::Open,
        'text' => 'Open Question Text',
        'options' => ['A', 'B'],
    ]);
    $answeredQ = Question::create([
        'project_id' => $project->id,
        'consensus_message_id' => $msg->id,
        'status' => QuestionStatus::Answered,
        'text' => 'Answered Question Text',
    ]);
    
    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Open Question Text')
        ->assertSee('A')
        ->assertSee('Answered Question Text')
        ->assertSee('answered');
});

test('gate pill shows remaining count or is absent', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $msg = ConsensusMessage::create(['project_id' => $project->id, 'role' => 'architect', 'content' => 'Q']);
    Question::create(['project_id' => $project->id, 'consensus_message_id' => $msg->id, 'status' => QuestionStatus::Open, 'text' => 'Q1']);
    Question::create(['project_id' => $project->id, 'consensus_message_id' => $msg->id, 'status' => QuestionStatus::Open, 'text' => 'Q2']);
    
    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertSee('2 questions remaining');
        
    $project->questions()->delete();
    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertDontSee('remaining');
});
