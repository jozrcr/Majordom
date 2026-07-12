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

test('custom free-text answer wins over a picked option', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $msg = ConsensusMessage::create(['project_id' => $project->id, 'role' => 'architect', 'content' => 'Q?']);
    $question = Question::create([
        'project_id' => $project->id,
        'consensus_message_id' => $msg->id,
        'status' => QuestionStatus::Open,
        'text' => 'Choose?',
        'options' => ['Yes', 'No'],
    ]);

    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->set('answerDrafts.'.$question->id, 'Yes')
        ->set('customDrafts.'.$question->id, 'Actually, your call — pick what is simplest.')
        ->call('answerQuestion', $question->id);

    expect($question->fresh()->answer)->toBe('Actually, your call — pick what is simplest.');
});

test('question card with options still offers a free-text field', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $msg = ConsensusMessage::create(['project_id' => $project->id, 'role' => 'architect', 'content' => 'Q?']);
    Question::create([
        'project_id' => $project->id,
        'consensus_message_id' => $msg->id,
        'status' => QuestionStatus::Open,
        'text' => 'Choose?',
        'options' => ['Yes', 'No'],
    ]);

    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertSee('your own words');
});

test('plan-approval card shows when last message claims consensus with zero open questions', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    ConsensusMessage::create([
        'project_id' => $project->id, 'role' => 'architect',
        'content' => 'We agree.', 'meta' => ['consensusClaimed' => true],
    ]);

    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Plan approval')
        ->assertSee('Approve plan');
});

test('plan-approval card absent when questions are open or after a newer message', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    $claim = ConsensusMessage::create([
        'project_id' => $project->id, 'role' => 'architect',
        'content' => 'We agree.', 'meta' => ['consensusClaimed' => true],
    ]);
    Question::create(['project_id' => $project->id, 'consensus_message_id' => $claim->id, 'status' => QuestionStatus::Open, 'text' => 'Wait, one more?']);

    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->assertDontSee('Plan approval');

    $project2 = Project::factory()->create(['name' => 'Other Project']);
    ConsensusMessage::create([
        'project_id' => $project2->id, 'role' => 'architect',
        'content' => 'We agree.', 'meta' => ['consensusClaimed' => true],
    ]);
    ConsensusMessage::create(['project_id' => $project2->id, 'role' => 'user', 'content' => 'Actually, wait.']);

    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project2])
        ->assertDontSee('Plan approval');
});

test('approvePlan dispatches RunPlanDraft and sets working status', function () {
    $project = Project::factory()->create(['name' => 'Test Project']);
    ConsensusMessage::create([
        'project_id' => $project->id, 'role' => 'architect',
        'content' => 'We agree.', 'meta' => ['consensusClaimed' => true],
    ]);

    Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->call('approvePlan');

    Queue::assertPushed(\App\Jobs\RunPlanDraft::class, fn ($job) => $job->projectId === $project->id);
    expect($project->fresh()->status)->toBe(\App\Enums\ProjectStatus::Working);
});
