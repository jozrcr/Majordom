<?php

use App\Agents\Providers\OpenAiCompatibleProvider;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRegistry;
use App\Models\ProviderEndpoint;
use App\Models\Role;
use App\Models\Task;
use App\Models\Execution;
use App\Models\Project;
use App\Models\Node;
use App\Core\Workflow\Nodes\BuildNode;
use App\Agents\Harness\Harness;
use App\Agents\Harness\HarnessRequest;
use App\Agents\Harness\HarnessResult;
use App\Agents\Harness\HarnessStatus;
use App\Runtime\Metallama\ResourceCoordinator;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Shared helpers setupMemoryRoot() / createExecutionWithTask() live in tests/Pest.php

test('migration seeded exactly the 2 builtin rows', function () {
    $rows = ProviderEndpoint::where('is_builtin', true)->get();
    expect($rows)->toHaveCount(2);
    expect($rows->pluck('name')->toArray())->toContain('openrouter', 'metallama');
});

test('chatBaseUrl appends /ollama/v1 for metallama driver', function () {
    $endpoint = ProviderEndpoint::named('metallama');
    expect($endpoint->chatBaseUrl())->toEndWith('/ollama/v1');
});

test('chatBaseUrl returns base_url as-is for openai_compatible', function () {
    $endpoint = ProviderEndpoint::named('openrouter');
    expect($endpoint->chatBaseUrl())->toBe($endpoint->base_url);
});

test('resolvedApiKey returns explicit api_key if set', function () {
    $endpoint = ProviderEndpoint::factory()->create(['api_key' => 'sk-explicit']);
    expect($endpoint->resolvedApiKey())->toBe('sk-explicit');
});

test('resolvedApiKey falls back to config path from meta', function () {
    config()->set('majordom.providers.test.api_key', 'sk-config-fallback');
    $endpoint = ProviderEndpoint::factory()->create([
        'api_key' => null,
        'meta' => ['api_key_config' => 'majordom.providers.test.api_key'],
    ]);
    expect($endpoint->resolvedApiKey())->toBe('sk-config-fallback');
});

test('registry returns OpenAiCompatibleProvider when no Provider bound', function () {
    $registry = app(ProviderRegistry::class);
    $provider = $registry->forName('openrouter');
    expect($provider)->toBeInstanceOf(OpenAiCompatibleProvider::class);
});

test('registry returns fake when Provider is bound in container', function () {
    $fake = new class implements Provider {
        public function chat(\App\Agents\Providers\ProviderRequest $request): \App\Agents\Providers\ProviderResponse
        {
            return new \App\Agents\Providers\ProviderResponse(
                content: 'fake',
                finishReason: 'stop',
                promptTokens: 0,
                completionTokens: 0,
            );
        }
    };
    app()->instance(Provider::class, $fake);
    
    $registry = app(ProviderRegistry::class);
    expect($registry->forName('openrouter'))->toBe($fake);
});

test('registry throws for unknown name', function () {
    app(ProviderRegistry::class)->forName('nonexistent');
})->throws(InvalidArgumentException::class);

test('BuildNode uses custom endpoint row and does not call ResourceCoordinator', function () {
    setupMemoryRoot();
    $repoDir = sys_get_temp_dir().'/majordom-noderepo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);
    
    $endpoint = ProviderEndpoint::factory()->create([
        'name' => 'ollama',
        'base_url' => 'http://127.0.0.1:11434/v1',
        'driver' => 'openai_compatible',
    ]);
    
    Role::create([
        'project_id' => null,
        'name' => 'builder',
        'provider' => 'ollama',
        'model' => 'custom-model',
    ]);

    [$execution, $task, $node, $project] = createExecutionWithTask([
        'worktree_path' => $repoDir,
    ], ['repo_path' => $repoDir]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Task");
    
    Config::set('queue.connections.harness.driver', 'sync');

    $fakeHarness = new class extends \App\Agents\Harness\AiderHarness {
        public HarnessRequest $lastRequest;
        public function __construct() {}
        public function runTask(HarnessRequest $request): HarnessResult {
            $this->lastRequest = $request;
            return new HarnessResult(
                status: HarnessStatus::Completed,
                diff: '',
                filesChanged: [],
                testsPassed: true,
                summary: 'Ok',
                openQuestions: [],
                rawLog: ''
            );
        }
    };
    app()->instance(Harness::class, $fakeHarness);
    
    $fakeCoordinator = new class extends ResourceCoordinator {
        public bool $ensureCalled = false;
        public function __construct() {}
        public function ensure(string $id): \App\Runtime\Metallama\ModelState {
            $this->ensureCalled = true;
            return new \App\Runtime\Metallama\ModelState(id: $id, status: \App\Runtime\Metallama\ServerStatus::Online);
        }
    };
    app()->instance(ResourceCoordinator::class, $fakeCoordinator);

    Process::fake([
        "'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n"),
    ]);

    (new BuildNode($node->id))->handle();

    expect($fakeHarness->lastRequest->endpointBaseUrl)->toBe('http://127.0.0.1:11434/v1');
    expect($fakeHarness->lastRequest->apiKey)->toBeNull();
    expect($fakeCoordinator->ensureCalled)->toBeFalse();
});

test('api_key is hidden from serialization', function () {
    $ep = \App\Models\ProviderEndpoint::factory()->create(['api_key' => 'sk-super-secret']);

    expect($ep->toArray())->not->toHaveKey('api_key')
        ->and($ep->toJson())->not->toContain('sk-super-secret')
        ->and($ep->resolvedApiKey())->toBe('sk-super-secret');
});
