<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function setupMemoryRoot(): string
{
    $root = sys_get_temp_dir().'/majordom-test-'.uniqid();
    \Illuminate\Support\Facades\Config::set('majordom.memory_root', $root);
    return $root;
}

function createExecutionWithTask(array $taskAttrs = [], array $projectAttrs = []): array
{
    $project = \App\Models\Project::factory()->create($projectAttrs);
    $task = \App\Models\Task::factory()->create(array_merge([
        'project_id' => $project->id,
        'task_key' => 'feat-1',
        'branch' => 'feat/branch-1',
        'status' => \App\Enums\TaskStatus::Pending,
        'revision' => 1,
    ], $taskAttrs));
    $execution = \App\Models\Execution::factory()->create(['project_id' => $project->id]);
    $execution->tasks()->save($task);
    $node = \App\Models\Node::factory()->create(['execution_id' => $execution->id]);
    return [$execution, $task, $node, $project];
}
