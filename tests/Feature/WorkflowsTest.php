<?php

use App\Core\Workflow\ImplementFeatureWorkflow;
use App\Core\Workflow\WorkflowEngine;
use App\Livewire\SettingsPage;
use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use App\Models\Workflow;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('seeder creates builtin workflow', function () {
    $this->artisan('db:seed', ['--class' => 'WorkflowSeeder']);
    expect(Workflow::where('name', 'Implement Feature')->first())
        ->is_builtin->toBeTrue()
        ->chain->toBe(ImplementFeatureWorkflow::CHAIN);
});

test('startForTask uses default chain when no workflow assigned', function () {
    Queue::fake();
    $project = Project::factory()->create();
    $task = ImplementFeatureWorkflow::startForTask($project, 'feat-1', 'Feature 1');

    $exec = $project->executions()->first();
    expect($exec->nodes->pluck('type')->toArray())->toBe(ImplementFeatureWorkflow::CHAIN);
});

test('startForTask uses custom chain and records workflow name', function () {
    Queue::fake();
    $wf = Workflow::factory()->create(['chain' => ['delegate', 'build']]);
    $project = Project::factory()->create(['workflow_id' => $wf->id]);
    
    ImplementFeatureWorkflow::startForTask($project, 'feat-2', 'Feature 2');

    $exec = $project->executions()->first();
    expect($exec->nodes->pluck('type')->toArray())->toBe(['delegate', 'build']);
    expect($exec->meta['workflow'])->toBe($wf->name);
});

test('engine knownTypes contains expected types', function () {
    $engine = new WorkflowEngine(ImplementFeatureWorkflow::nodeMap());
    expect($engine->knownTypes())->toContain('delegate', 'build', 'test', 'review', 'commit_suggestion');
});

test('settings page creates workflow', function () {
    \Livewire\Livewire::test(SettingsPage::class)
        ->set('section', 'workflows')
        ->set('workflowName', 'Custom Flow')
        ->set('workflowDescription', 'A test flow')
        ->set('chainDraft', ['delegate', 'build'])
        ->call('saveWorkflow');

    // Chains persist as step objects since M9 (strings normalize on save).
    expect(Workflow::where('name', 'Custom Flow')->first())
        ->chain->toBe([
            ['type' => 'delegate', 'role' => 'system', 'config' => []],
            ['type' => 'build', 'role' => 'builder', 'config' => []],
        ]);
});

test('settings page moveStep and removeStep work', function () {
    \Livewire\Livewire::test(SettingsPage::class)
        ->set('chainDraft', ['a', 'b', 'c'])
        ->call('moveStep', 0, 'down')
        ->assertSet('chainDraft', ['b', 'a', 'c'])
        ->call('removeStep', 1)
        ->assertSet('chainDraft', ['b', 'c']);
});

test('settings page prevents duplicate names', function () {
    Workflow::factory()->create(['name' => 'Existing']);
    \Livewire\Livewire::test(SettingsPage::class)
        ->set('workflowName', 'Existing')
        ->set('chainDraft', ['delegate'])
        ->call('saveWorkflow')
        ->assertHasErrors(['workflowName']);
});

test('settings page prevents deleting used workflow', function () {
    $wf = Workflow::factory()->create();
    Project::factory()->create(['workflow_id' => $wf->id]);
    
    \Livewire\Livewire::test(SettingsPage::class)
        ->call('deleteWorkflow', $wf->id)
        ->assertHasErrors(['workflow']);
    
    expect($wf->fresh())->not->toBeNull();
});

test('settings page prevents deleting builtin workflow', function () {
    $wf = Workflow::factory()->create(['is_builtin' => true]);
    \Livewire\Livewire::test(SettingsPage::class)
        ->call('deleteWorkflow', $wf->id)
        ->assertHasErrors(['workflow']);
});

test('workspace select saves workflow_id', function () {
    $project = Project::factory()->create();
    $wf = Workflow::factory()->create();
    
    \Livewire\Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->set('workflowId', $wf->id)
        ->assertSet('workflowId', $wf->id);
        
    expect($project->fresh()->workflow_id)->toBe($wf->id);
});
