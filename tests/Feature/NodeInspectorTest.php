<?php

use App\Livewire\ProjectWorkspace;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use Livewire\Livewire;

test('clicking a chip sets inspectedNodeId and shows panel with input/output', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $node = Node::factory()->create([
        'execution_id' => $exec->id,
        'type' => 'build',
        'status' => NodeStatus::Completed,
        'input' => ['step' => 'compile'],
        'output' => ['result' => 'success'],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('inspectNode', $node->id)
        ->assertSet('inspectedNodeId', $node->id)
        ->assertSee('build')
        ->assertSee('"step": "compile"')
        ->assertSee('"result": "success"');
});

test('clicking the same chip again closes the panel', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $node = Node::factory()->create(['execution_id' => $exec->id, 'type' => 'build', 'status' => NodeStatus::Completed]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('inspectNode', $node->id)
        ->assertSet('inspectedNodeId', $node->id)
        ->call('inspectNode', $node->id)
        ->assertSet('inspectedNodeId', null);
});

test('failed node shows error detail string', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $node = Node::factory()->create([
        'execution_id' => $exec->id,
        'type' => 'test',
        'status' => NodeStatus::Failed,
        'output' => ['error' => 'Compilation failed: missing semicolon'],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('inspectNode', $node->id)
        ->assertSee('Compilation failed: missing semicolon');
});

test('inspectNode with node ID from different project renders no panel', function () {
    $project1 = Project::factory()->create();
    $exec1 = Execution::factory()->create(['project_id' => $project1->id, 'status' => ExecutionStatus::Running]);
    $node1 = Node::factory()->create([
        'execution_id' => $exec1->id,
        'type' => 'build',
        'status' => NodeStatus::Completed,
        'input' => ['target' => 'cross-project-node'],
    ]);

    $project2 = Project::factory()->create();

    Livewire::test(ProjectWorkspace::class, ['project' => $project2])
        ->call('inspectNode', $node1->id)
        ->assertSet('inspectedNodeId', $node1->id)
        ->assertDontSee('cross-project-node');
});

test('node with null input/output renders panel without sections and no errors', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $node = Node::factory()->create([
        'execution_id' => $exec->id,
        'type' => 'decompose',
        'status' => NodeStatus::Completed,
        'input' => null,
        'output' => null,
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('inspectNode', $node->id)
        ->assertSee('decompose')
        ->assertDontSee('Input')
        ->assertDontSee('Output');
});
