<?php

use App\Livewire\ProjectWorkspace;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use Livewire\Livewire;

test('clicking a chip sets inspectedNodeId and shows panel with humanized input/output', function () {
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
        ->assertSee('step')
        ->assertSee('compile')
        ->assertSee('result')
        ->assertSee('success')
        ->assertSee('View raw');
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

test('review node output renders verdict summary and comments', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $node = Node::factory()->create([
        'execution_id' => $exec->id,
        'type' => 'review',
        'status' => NodeStatus::Completed,
        'output' => [
            'verdict' => [
                'approved' => false,
                'summary' => 'Needs work on the parser',
                'comments' => [['file' => 'app/Parser.php', 'comment' => 'Handle empty input']],
            ],
        ],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('inspectNode', $node->id)
        ->assertSee('Needs work on the parser')
        ->assertSee('app/Parser.php')
        ->assertSee('Handle empty input')
        ->assertDontSee('{"file"');
});

test('build node output renders summary, files, and tests badge', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $node = Node::factory()->create([
        'execution_id' => $exec->id,
        'type' => 'build',
        'status' => NodeStatus::Completed,
        'output' => [
            'summary' => 'Added parser',
            'filesChanged' => ['app/Parser.php'],
            'testsPassed' => true,
            'diff' => "+ new line",
            'rawLog' => 'log text',
        ],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('inspectNode', $node->id)
        ->assertSee('Added parser')
        ->assertSee('app/Parser.php')
        ->assertSee('Tests:')
        ->assertSee('passed');
});

test('unknown node type falls back to key-value rendering', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $node = Node::factory()->create([
        'execution_id' => $exec->id,
        'type' => 'custom',
        'status' => NodeStatus::Completed,
        'output' => ['foo' => 'bar'],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('inspectNode', $node->id)
        ->assertSee('foo')
        ->assertSee('bar');
});

test('long string values render truncated with a show-more toggle without erroring', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $long = str_repeat('lorem ipsum ', 80); // > 600 chars
    $node = Node::factory()->create([
        'execution_id' => $exec->id,
        'type' => 'custom',
        'status' => NodeStatus::Completed,
        'input' => ['prompt' => $long],
        'output' => ['log' => $long],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('inspectNode', $node->id)
        ->assertSee('show more')
        ->assertSee('prompt')
        ->assertSee('log');
});

test('array values in input config and extra keys render placeholder, not a crash', function () {
    $project = Project::factory()->create();
    $exec = Execution::factory()->create(['project_id' => $project->id, 'status' => ExecutionStatus::Running]);
    $node = Node::factory()->create([
        'execution_id' => $exec->id,
        'type' => 'custom',
        'status' => NodeStatus::Completed,
        'input' => [
            'role' => 'builder',
            'config' => ['retries' => 3, 'matrix' => ['a' => 1]],
            'extras' => ['nested' => true],
        ],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->call('inspectNode', $node->id)
        ->assertSee('Role:')
        ->assertSee('builder')
        ->assertSee('retries')
        ->assertSee('nested — see raw');
});
