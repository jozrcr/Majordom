<?php

use App\Models\ConsensusMessage;
use App\Models\Project;
use App\Models\Question;
use App\Enums\MessageRole;
use App\Enums\QuestionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('a message round-trips role as enum and meta as array', function () {
    $project = Project::factory()->create();
    $message = ConsensusMessage::factory()->for($project)->create([
        'role' => MessageRole::User,
        'meta' => ['key' => 'value'],
    ]);

    expect($message->fresh()->role)->toBe(MessageRole::User)
        ->and($message->fresh()->meta)->toBe(['key' => 'value']);
});

test('question answerWith sets status, answer, and answered_at', function () {
    $question = Question::factory()->create();
    $question->answerWith('yes');

    expect($question->fresh()->status)->toBe(QuestionStatus::Answered)
        ->and($question->fresh()->answer)->toBe('yes')
        ->and($question->fresh()->answered_at)->not()->toBeNull();
});

test('Project openQuestions returns only open questions', function () {
    $project = Project::factory()->create();
    Question::factory()->count(2)->for($project)->create(['status' => QuestionStatus::Open]);
    Question::factory()->for($project)->create(['status' => QuestionStatus::Answered, 'answer' => 'test', 'answered_at' => now()]);

    expect($project->openQuestions()->count())->toBe(2);
});

test('deleting a project cascades its messages and questions', function () {
    $project = Project::factory()->has(ConsensusMessage::factory()->count(2))->has(Question::factory()->count(2))->create();
    $projectId = $project->id;

    $project->delete();

    expect(ConsensusMessage::where('project_id', $projectId)->count())->toBe(0)
        ->and(Question::where('project_id', $projectId)->count())->toBe(0);
});

test('Question open scope filters correctly', function () {
    Question::factory()->count(2)->create(['status' => QuestionStatus::Open]);
    Question::factory()->count(1)->create(['status' => QuestionStatus::Answered]);

    expect(Question::open()->count())->toBe(2);
});
