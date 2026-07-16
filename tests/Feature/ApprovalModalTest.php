<?php

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Livewire\ProjectWorkspace;
use App\Models\Approval;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('HumanTask approval open renders modal on overview tab', function () {
    $project = Project::factory()->create();
    Approval::factory()->create([
        'project_id' => $project->id,
        'type' => ApprovalType::HumanTask,
        'status' => ApprovalStatus::Open,
        'title' => 'Manual Database Migration',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('tab', 'overview')
        ->assertSee('Manual Database Migration')
        ->assertSee('Waiting: your task');
});

test('Review approval open resolves on approveApproval', function () {
    $project = Project::factory()->create();
    $approval = Approval::factory()->create([
        'project_id' => $project->id,
        'type' => ApprovalType::Review,
        'status' => ApprovalStatus::Open,
        'title' => 'Feature X PR',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('approveApproval')
        ->assertDontSee('Feature X PR');

    $approval->refresh();
    expect($approval->status)->toBe(ApprovalStatus::Granted);
});

test('Review approval reject with empty comment surfaces error', function () {
    $project = Project::factory()->create();
    Approval::factory()->create([
        'project_id' => $project->id,
        'type' => ApprovalType::Review,
        'status' => ApprovalStatus::Open,
        'title' => 'Feature Y PR',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('gateComment', '')
        ->call('rejectApproval')
        ->assertSee('Say why — the comment becomes the revision brief.')
        ->assertSee('Feature Y PR');
});

test('MilestoneMerge approval reject with empty comment succeeds', function () {
    $project = Project::factory()->create();
    Approval::factory()->create([
        'project_id' => $project->id,
        'type' => ApprovalType::MilestoneMerge,
        'status' => ApprovalStatus::Open,
        'title' => 'Milestone 1',
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('gateComment', '')
        ->call('rejectApproval')
        ->assertDontSee('Milestone complete');
});

test('no open approval does not render modal header', function () {
    $project = Project::factory()->create();

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertDontSee('Waiting: your task')
        ->assertDontSee('Review gate')
        ->assertDontSee('Milestone complete');
});
