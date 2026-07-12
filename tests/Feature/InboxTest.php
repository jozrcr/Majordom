<?php

use App\Livewire\Inbox;
use App\Models\Approval;
use App\Models\CommitSuggestion;
use App\Models\Project;
use App\Models\Question;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('page renders 200 with Needs you', function () {
    config(['majordom.token' => 'secret']);

    $this->withHeader('Authorization', 'Bearer secret')
        ->get(route('inbox'))
        ->assertStatus(200)
        ->assertSee('Needs you');
});

test('one of each source shows with its label and count pill shows 3', function () {
    $project = Project::factory()->create();
    Question::factory()->create(['project_id' => $project->id]);
    Approval::factory()->create(['project_id' => $project->id]);
    CommitSuggestion::factory()->create(['project_id' => $project->id, 'status' => 'suggested']);

    Livewire::test(Inbox::class)
        ->assertSee('QUESTION')
        ->assertSee('ARBITRATE')
        ->assertSee('COMMIT READY')
        ->assertSee('3');
});

test('archived project items excluded', function () {
    $project = Project::factory()->create(['archived_at' => now()]);
    Question::factory()->create(['project_id' => $project->id]);
    
    Livewire::test(Inbox::class)
        ->assertSee('All quiet');
});

test('projectFilter narrows to one project items', function () {
    $p1 = Project::factory()->create();
    $p2 = Project::factory()->create();
    $q1 = Question::factory()->create(['project_id' => $p1->id, 'text' => 'Only in project one?']);
    $q2 = Question::factory()->create(['project_id' => $p2->id, 'text' => 'Only in project two?']);

    Livewire::test(Inbox::class)
        ->set('projectFilter', $p1->id)
        ->assertSee('Only in project one?')
        ->assertDontSee('Only in project two?');
});

test('resolved answered committed items dont appear', function () {
    $project = Project::factory()->create();
    Question::factory()->create(['project_id' => $project->id, 'status' => 'answered']);
    Approval::factory()->create(['project_id' => $project->id, 'status' => 'granted']);
    CommitSuggestion::factory()->create(['project_id' => $project->id, 'status' => 'committed']);

    Livewire::test(Inbox::class)
        ->assertSee('All quiet');
});

test('empty state renders All quiet with zero items', function () {
    Livewire::test(Inbox::class)
        ->assertSee('All quiet')
        ->assertSee('Nothing needs you. The estate runs itself tonight.');
});

test('openCount returns the right number', function () {
    $project = Project::factory()->create();
    Question::factory()->create(['project_id' => $project->id]);
    Approval::factory()->create(['project_id' => $project->id]);
    CommitSuggestion::factory()->create(['project_id' => $project->id, 'status' => 'suggested']);

    expect(Inbox::openCount())->toBe(3);
});
