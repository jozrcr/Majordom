<?php

use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use App\Models\Question;
use App\Enums\QuestionStatus;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('overview shows summary from architecture.md', function () {
    $project = Project::factory()->create();
    app(\App\Projects\Memory\MemoryStore::class)->write($project, 'architecture.md', 'GOAL: build the thing');

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('tab', 'overview')
        ->assertSee('GOAL: build the thing');
});

test('overview does not show the Agreed Plan card', function () {
    $project = Project::factory()->create();

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('tab', 'overview')
        ->assertDontSee('Agreed Plan')
        ->assertDontSee('First task:');
});

test('agreed specs render', function () {
    $project = Project::factory()->create();
    Question::create([
        'project_id' => $project->id,
        'text' => 'Which DB?',
        'answer' => 'sqlite',
        'status' => QuestionStatus::Answered,
        'answered_at' => now(),
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('tab', 'overview')
        ->assertSee('Which DB?')
        ->assertSee('sqlite');
});

test('empty states show fallback messages', function () {
    $project = Project::factory()->create();

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('tab', 'overview')
        ->assertSee('No summary yet')
        ->assertSee('No settled specs yet');
});
