<?php

use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Jobs\RunArchitectTurn;
use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('shows the recovery card when the Architect stalled (reply, no question, no consensus)', function () {
    $project = Project::factory()->create();
    $project->consensusMessages()->create([
        'role' => MessageRole::Architect,
        'content' => 'Let me think about the structure…',
        'meta' => ['consensusClaimed' => false],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Architect paused')
        ->assertSee('Nudge the Architect');
});

it('does not show the recovery card when a question is open', function () {
    $project = Project::factory()->create();
    $msg = $project->consensusMessages()->create([
        'role' => MessageRole::Architect,
        'content' => 'One thing first.',
        'meta' => ['consensusClaimed' => false],
    ]);
    $project->questions()->create(['consensus_message_id' => $msg->id, 'text' => 'Which DB?']);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertDontSee('Architect paused');
});

it('nudge re-arms the turn with a corrective system note', function () {
    Queue::fake();

    $project = Project::factory()->create();
    $project->consensusMessages()->create([
        'role' => MessageRole::Architect,
        'content' => 'Hmm.',
        'meta' => ['consensusClaimed' => false],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('nudgeArchitect');

    $system = $project->consensusMessages()->where('role', MessageRole::System)->get();
    expect($system)->toHaveCount(1)
        ->and($system->first()->meta['nudge'] ?? false)->toBeTrue()
        ->and($project->fresh()->status)->toBe(ProjectStatus::Working);

    Queue::assertPushed(RunArchitectTurn::class);
});

it('nudge is a no-op when the project is not stalled', function () {
    Queue::fake();

    $project = Project::factory()->create(); // no consensus messages at all

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('nudgeArchitect');

    expect($project->consensusMessages()->count())->toBe(0);
    Queue::assertNotPushed(RunArchitectTurn::class);
});
