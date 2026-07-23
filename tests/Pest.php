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

/*
| M15 tool-contract helpers — build the ProviderResponse a scripted Provider
| returns for each Architect consensus turn. The scripted providers
| (ScriptedProvider / InspectScriptedProvider) pass a ProviderResponse entry
| through verbatim, so a test scripts a turn with one of these.
*/

function archReply(string $text): \App\Agents\Providers\ProviderResponse
{
    return new \App\Agents\Providers\ProviderResponse($text, 'stop', 5, 5);
}

/** ask_owner: $questions are strings or ['text'=>…, 'options'=>[…]] maps. */
function archAsk(array $questions, string $reply = ''): \App\Agents\Providers\ProviderResponse
{
    $norm = array_map(fn ($q) => is_string($q) ? ['text' => $q] : $q, $questions);

    return new \App\Agents\Providers\ProviderResponse($reply, 'tool_calls', 5, 5, [
        new \App\Agents\Providers\ToolCall('call_ask', 'ask_owner', ['questions' => $norm]),
    ]);
}

function archPropose(array $plan, string $reply = ''): \App\Agents\Providers\ProviderResponse
{
    return new \App\Agents\Providers\ProviderResponse($reply, 'tool_calls', 5, 5, [
        new \App\Agents\Providers\ToolCall('call_plan', 'propose_plan', $plan),
    ]);
}

function archReadFile(string $path): \App\Agents\Providers\ProviderResponse
{
    return new \App\Agents\Providers\ProviderResponse('', 'tool_calls', 5, 5, [
        new \App\Agents\Providers\ToolCall('call_read', 'read_file', ['path' => $path]),
    ]);
}

function archListRepo(?string $path = null): \App\Agents\Providers\ProviderResponse
{
    $args = $path === null ? [] : ['path' => $path];

    return new \App\Agents\Providers\ProviderResponse('', 'tool_calls', 5, 5, [
        new \App\Agents\Providers\ToolCall('call_list', 'list_repo', $args),
    ]);
}

/** A complete, buildable plan payload for propose_plan (override any field). */
function samplePlan(array $overrides = []): array
{
    return array_merge([
        'architecture_md' => '# Arch',
        'roadmap_md' => "## M1 — Skeleton\nStand up the shell.\n- [ ] T-001 — First task",
        'first_task_id' => 'T-001',
        'first_task_md' => '# Task 1',
        'summary' => 'We build X.',
    ], $overrides);
}

/*
| M15 milestone-review tool-call helpers.
*/

function archReviewReadDiff(): \App\Agents\Providers\ProviderResponse
{
    return new \App\Agents\Providers\ProviderResponse('', 'tool_calls', 5, 5, [
        new \App\Agents\Providers\ToolCall('c_rd', 'read_diff', []),
    ]);
}

function archReviewApprove(string $summary = 'looks good'): \App\Agents\Providers\ProviderResponse
{
    return new \App\Agents\Providers\ProviderResponse('', 'tool_calls', 5, 5, [
        new \App\Agents\Providers\ToolCall('c_ap', 'approve_milestone', ['summary' => $summary]),
    ]);
}

/** $items are strings or ['task_key'=>…,'file'=>…,'reason'=>…] maps. */
function archReviewChanges(array $items, string $summary = 'needs work'): \App\Agents\Providers\ProviderResponse
{
    $norm = array_map(fn ($i) => is_string($i) ? ['reason' => $i] : $i, $items);

    return new \App\Agents\Providers\ProviderResponse('', 'tool_calls', 5, 5, [
        new \App\Agents\Providers\ToolCall('c_ch', 'request_changes', ['summary' => $summary, 'items' => $norm]),
    ]);
}

function archReviewEscalate(array $questions, string $summary = ''): \App\Agents\Providers\ProviderResponse
{
    return new \App\Agents\Providers\ProviderResponse('', 'tool_calls', 5, 5, [
        new \App\Agents\Providers\ToolCall('c_es', 'ask_owner', ['summary' => $summary, 'questions' => $questions]),
    ]);
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
