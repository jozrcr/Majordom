<?php

use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use Livewire\Livewire;

test('renders settings tab and control labels', function () {
    $project = Project::factory()->create();

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('tab', 'settings')
        ->assertSee('Settings')
        ->assertSee('Rename')
        ->assertSee('Archive')
        ->assertSee('Autonomy profile')
        ->assertSee('Confirm commits')
        ->assertSee('Push after merge')
        ->assertSee('Night mode');
});

test('rename project updates name but keeps slug', function () {
    $project = Project::factory()->create(['name' => 'Old Name', 'slug' => 'old-name']);
    $originalSlug = $project->slug;

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('settingsName', 'New Name')
        ->call('renameProject');

    $project->refresh();
    expect($project->name)->toBe('New Name');
    expect($project->slug)->toBe($originalSlug);
});

test('toggle confirm commits flips column', function () {
    $project = Project::factory()->create(['confirm_commits' => false]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('toggleConfirmCommits');

    $project->refresh();
    expect($project->confirm_commits)->toBeTrue();
});

test('switch profile updates profile', function () {
    $project = Project::factory()->create();

    $component = Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('switchProfile', 'overnight');

    $component->assertSet('buildProfile', 'overnight');
});

test('common header renders on other tabs', function () {
    $project = Project::factory()->create();

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('tab', 'stats')
        ->assertSee($project->name);
});
