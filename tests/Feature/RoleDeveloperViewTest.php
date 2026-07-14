<?php

use App\Agents\Providers\OpenAiCompatibleProvider;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Providers\ProviderRegistry;
use App\Agents\Architect\ArchitectService;
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
use App\Livewire\SettingsPage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Process;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('ProviderRequest passthrough includes sampler params when set', function () {
    $provider = new OpenAiCompatibleProvider(
        baseUrl: 'http://test.local/v1',
        apiKey: 'sk-test',
        timeout: 30,
    );

    $request = new ProviderRequest(
        model: 'test-model',
        messages: [['role' => 'user', 'content' => 'hi']],
        topP: 0.9,
        frequencyPenalty: 0.5,
        presencePenalty: -1.0,
        stop: ['\n', 'END'],
        timeout: 60,
    );

    // ResponseSequence has no pushJson(); plain Http::fake stub instead.
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]], 200)]);

    $provider->chat($request);

    Http::assertSent(function ($request) {
        $body = $request->data();
        return $body['top_p'] === 0.9
            && $body['frequency_penalty'] === 0.5
            && (float) $body['presence_penalty'] === -1.0 // json_encode(-1.0) → "-1", decodes as int
            && $body['stop'] === ['\n', 'END'];
    });
});

test('ProviderRequest passthrough omits sampler params when null', function () {
    $provider = new OpenAiCompatibleProvider(
        baseUrl: 'http://test.local/v1',
        apiKey: 'sk-test',
        timeout: 30,
    );

    $request = new ProviderRequest(
        model: 'test-model',
        messages: [['role' => 'user', 'content' => 'hi']],
    );

    // ResponseSequence has no pushJson(); plain Http::fake stub instead.
    Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{}'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]], 200)]);

    $provider->chat($request);

    Http::assertSent(function ($request) {
        $body = $request->data();
        return !isset($body['top_p'])
            && !isset($body['frequency_penalty'])
            && !isset($body['presence_penalty'])
            && !isset($body['stop']);
    });
});

test('ArchitectService appends system_prompt_extra to system prompt', function () {
    $project = Project::create(['name' => 'test', 'slug' => 'test', 'repo_path' => '/tmp/test']);
    $role = Role::create(['project_id' => null, 'name' => 'architect', 'provider' => 'openrouter', 'model' => 'gpt-4', 'meta' => ['system_prompt_extra' => 'CUSTOM INSTRUCTION']]);
    
    $fakeProvider = new class implements Provider {
        public array $lastMessages = [];
        public function chat(ProviderRequest $request): ProviderResponse {
            $this->lastMessages = $request->messages;
            return new ProviderResponse(content: '{"reply":"ok","questions":[],"consensus_reached":false}', finishReason: 'stop', promptTokens: 0, completionTokens: 0);
        }
    };
    app()->instance(Provider::class, $fakeProvider);

    $service = app(ArchitectService::class);
    $service->converse($project, 'hello');

    $systemContent = $fakeProvider->lastMessages[0]['content'];
    expect($systemContent)->toContain('CUSTOM INSTRUCTION');
});

test('ArchitectService omits system_prompt_extra when absent', function () {
    $project = Project::create(['name' => 'test2', 'slug' => 'test2', 'repo_path' => '/tmp/test2']);
    Role::create(['project_id' => null, 'name' => 'architect', 'provider' => 'openrouter', 'model' => 'gpt-4', 'meta' => []]);
    
    $fakeProvider = new class implements Provider {
        public array $lastMessages = [];
        public function chat(ProviderRequest $request): ProviderResponse {
            $this->lastMessages = $request->messages;
            return new ProviderResponse(content: '{"reply":"ok","questions":[],"consensus_reached":false}', finishReason: 'stop', promptTokens: 0, completionTokens: 0);
        }
    };
    app()->instance(Provider::class, $fakeProvider);

    $service = app(ArchitectService::class);
    $service->converse($project, 'hello');

    $systemContent = $fakeProvider->lastMessages[0]['content'];
    expect($systemContent)->not->toContain('CUSTOM INSTRUCTION');
});

test('BuildNode appends extra_instructions to task prompt', function () {
    setupMemoryRoot();
    $repoDir = sys_get_temp_dir().'/majordom-noderepo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);
    
    $endpoint = ProviderEndpoint::factory()->create(['name' => 'ollama', 'base_url' => 'http://127.0.0.1:11434/v1', 'driver' => 'openai_compatible']);
    Role::create(['project_id' => null, 'name' => 'builder', 'provider' => 'ollama', 'model' => 'custom-model', 'meta' => ['extra_instructions' => 'DO THIS EXTRA']]);

    [$execution, $task, $node, $project] = createExecutionWithTask(['worktree_path' => $repoDir], ['repo_path' => $repoDir]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Task");
    
    Config::set('queue.connections.harness.driver', 'sync');

    $fakeHarness = new class extends \App\Agents\Harness\AiderHarness {
        public HarnessRequest $lastRequest;
        public function __construct() {}
        public function runTask(HarnessRequest $request): HarnessResult {
            $this->lastRequest = $request;
            return new HarnessResult(status: HarnessStatus::Completed, diff: '', filesChanged: [], testsPassed: true, summary: 'Ok', openQuestions: [], rawLog: '');
        }
    };
    app()->instance(Harness::class, $fakeHarness);
    
    $fakeCoordinator = new class extends ResourceCoordinator {
        public function __construct() {}
        public function ensure(string $id): \App\Runtime\Metallama\ModelState {
            return new \App\Runtime\Metallama\ModelState(id: $id, status: \App\Runtime\Metallama\ServerStatus::Online);
        }
    };
    app()->instance(ResourceCoordinator::class, $fakeCoordinator);

    Process::fake(["'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n")]);

    (new BuildNode($node->id))->handle();

    expect($fakeHarness->lastRequest->taskPrompt)->toContain('## Owner role instructions');
    expect($fakeHarness->lastRequest->taskPrompt)->toContain('DO THIS EXTRA');
});

test('BuildNode omits extra_instructions when absent', function () {
    setupMemoryRoot();
    $repoDir = sys_get_temp_dir().'/majordom-noderepo-'.uniqid();
    mkdir($repoDir.'/.git', 0755, true);
    
    $endpoint = ProviderEndpoint::factory()->create(['name' => 'ollama2', 'base_url' => 'http://127.0.0.1:11434/v1', 'driver' => 'openai_compatible']);
    Role::create(['project_id' => null, 'name' => 'builder', 'provider' => 'ollama2', 'model' => 'custom-model', 'meta' => []]);

    [$execution, $task, $node, $project] = createExecutionWithTask(['worktree_path' => $repoDir], ['repo_path' => $repoDir]);
    
    $memory = app(MemoryStore::class);
    $memory->write($project, "tasks/{$task->task_key}/role.md", "Role");
    $memory->write($project, "tasks/{$task->task_key}/task.md", "Task");
    
    Config::set('queue.connections.harness.driver', 'sync');

    $fakeHarness = new class extends \App\Agents\Harness\AiderHarness {
        public HarnessRequest $lastRequest;
        public function __construct() {}
        public function runTask(HarnessRequest $request): HarnessResult {
            $this->lastRequest = $request;
            return new HarnessResult(status: HarnessStatus::Completed, diff: '', filesChanged: [], testsPassed: true, summary: 'Ok', openQuestions: [], rawLog: '');
        }
    };
    app()->instance(Harness::class, $fakeHarness);
    
    $fakeCoordinator = new class extends ResourceCoordinator {
        public function __construct() {}
        public function ensure(string $id): \App\Runtime\Metallama\ModelState {
            return new \App\Runtime\Metallama\ModelState(id: $id, status: \App\Runtime\Metallama\ServerStatus::Online);
        }
    };
    app()->instance(ResourceCoordinator::class, $fakeCoordinator);

    Process::fake(["'git' 'rev-parse' '--verify' 'HEAD'" => Process::result(output: "abc123\n")]);

    (new BuildNode($node->id))->handle();

    expect($fakeHarness->lastRequest->taskPrompt)->not->toContain('## Owner role instructions');
});

test('Livewire round-trips developer fields into roles.meta', function () {
    $role = Role::create(['name' => 'test-role', 'provider' => 'openrouter', 'model' => 'gpt-4', 'is_builtin' => false]);
    
    Livewire::test(SettingsPage::class)
        ->set("roleDrafts.{$role->id}.system_prompt_extra", 'Extra sys')
        ->set("roleDrafts.{$role->id}.extra_instructions", 'Extra instr')
        ->set("roleDrafts.{$role->id}.top_p", '0.8')
        ->set("roleDrafts.{$role->id}.frequency_penalty", '0.5')
        ->set("roleDrafts.{$role->id}.presence_penalty", '-1.0')
        ->set("roleDrafts.{$role->id}.stop", 'END, STOP')
        ->set("roleDrafts.{$role->id}.timeout", '120')
        ->call("saveRole", $role->id);

    $role->refresh();
    $meta = $role->meta;
    
    expect($meta['system_prompt_extra'])->toBe('Extra sys');
    expect($meta['extra_instructions'])->toBe('Extra instr');
    expect($meta['top_p'])->toBe(0.8);
    expect($meta['frequency_penalty'])->toBe(0.5);
    expect((float) $meta['presence_penalty'])->toBe(-1.0); // JSON column round-trip stores -1.0 as -1; services cast (float) on read
    expect($meta['stop'])->toBe(['END', 'STOP']);
    expect($meta['timeout'])->toBe(120);
});

test('Livewire blank developer fields do not pollute meta', function () {
    $role = Role::create(['name' => 'test-role-2', 'provider' => 'openrouter', 'model' => 'gpt-4', 'is_builtin' => false, 'meta' => ['top_p' => 0.9]]);
    
    Livewire::test(SettingsPage::class)
        ->set("roleDrafts.{$role->id}.top_p", '')
        ->call("saveRole", $role->id);

    $role->refresh();
    expect($role->meta)->not->toHaveKey('top_p');
});
