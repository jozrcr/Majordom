<?php

use App\Livewire\ProjectWorkspace;
use App\Models\CommitSuggestion;
use App\Models\Execution;
use App\Models\Project;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('commit card modal renders merge into branch name', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->for($project)->create();
    CommitSuggestion::create([
        'execution_id' => $exec->id,
        'status' => 'suggested',
        'branch' => 'feat/test-branch',
        'message' => 'Test commit message',
        'diff' => '',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Merge into feat/test-branch')
        ->assertSee('COMMIT READY');
});

test('reject button does not appear in commit modal', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->for($project)->create();
    CommitSuggestion::create([
        'execution_id' => $exec->id,
        'status' => 'suggested',
        'branch' => 'feat/test-branch',
        'message' => 'Test commit message',
        'diff' => '',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertDontSee('Reject');
});

test('commit warning popup shows and hides', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->for($project)->create();
    CommitSuggestion::create([
        'execution_id' => $exec->id,
        'status' => 'suggested',
        'branch' => 'feat/test-branch',
        'message' => 'Test commit message',
        'diff' => '',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('commitWarning', 'Uncommitted changes in working tree.')
        ->assertSee("CAN'T MERGE")
        ->assertSee('Uncommitted changes in working tree.')
        ->set('commitWarning', null)
        ->assertDontSee("CAN'T MERGE");
});

test('rework commit with empty comment adds error', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->for($project)->create();
    CommitSuggestion::create([
        'execution_id' => $exec->id,
        'status' => 'suggested',
        'branch' => 'feat/test-branch',
        'message' => 'Test commit message',
        'diff' => '',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('commitComment', '')
        ->call('reworkCommit')
        ->assertHasErrors('commitComment');
});
