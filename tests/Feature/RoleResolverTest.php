<?php

use App\Models\Project;
use App\Models\Role;
use App\Support\RoleResolver;
use App\Support\RoleBinding;
use Illuminate\Support\Facades\Config;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

test('no rows mirrors config values for architect', function () {
    $resolver = app(RoleResolver::class);
    $binding = $resolver->resolve('architect');

    expect($binding)->toBeInstanceOf(RoleBinding::class)
        ->and($binding->provider)->toBe('openrouter')
        ->and($binding->model)->toBe(Config::get('majordom.architect.model'))
        ->and($binding->temperature)->toBe(Config::get('majordom.architect.temperature'))
        ->and($binding->maxTokens)->toBe(Config::get('majordom.architect.max_tokens'));
});

test('global row overrides config', function () {
    Role::create([
        'project_id' => null,
        'name' => 'architect',
        'provider' => 'openrouter',
        'model' => 'x/y',
        'temperature' => 0.5,
        'max_tokens' => 5000,
    ]);

    $binding = app(RoleResolver::class)->resolve('architect');

    expect($binding->model)->toBe('x/y')
        ->and($binding->temperature)->toBe(0.5)
        ->and($binding->maxTokens)->toBe(5000);
});

test('project row overrides global', function () {
    Role::create([
        'project_id' => null,
        'name' => 'architect',
        'provider' => 'openrouter',
        'model' => 'x/y',
    ]);

    $project = Project::factory()->create();
    Role::create([
        'project_id' => $project->id,
        'name' => 'architect',
        'provider' => 'openrouter',
        'model' => 'z/w',
    ]);

    $resolver = app(RoleResolver::class);
    expect($resolver->resolve('architect', $project)->model)->toBe('z/w');
    
    $otherProject = Project::factory()->create();
    expect($resolver->resolve('architect', $otherProject)->model)->toBe('x/y');
});

test('builder fallback carries managed_model meta', function () {
    $binding = app(RoleResolver::class)->resolve('builder');
    
    expect($binding->provider)->toBe('metallama')
        ->and($binding->meta)->toHaveKey('managed_model')
        ->and($binding->meta['managed_model'])->toBe(Config::get('majordom.builder.model'));
});

test('unknown role name without a row throws', function () {
    app(RoleResolver::class)->resolve('unknown_role');
})->throws(InvalidArgumentException::class);

test('ArchitectService uses a DB role', function () {
    Role::create([
        'project_id' => null,
        'name' => 'architect',
        'provider' => 'openrouter',
        'model' => 'custom/model',
        'temperature' => 0.3,
        'max_tokens' => 4000,
    ]);

    $project = Project::factory()->create();

    $fakeProvider = new class implements \App\Agents\Providers\Provider {
        public static $lastRequest = null;
        public function chat(\App\Agents\Providers\ProviderRequest $request): \App\Agents\Providers\ProviderResponse {
            self::$lastRequest = $request;
            return new \App\Agents\Providers\ProviderResponse(
                json_encode(['reply' => 'ok', 'questions' => [], 'consensus_reached' => false]),
                'stop', 10, 20,
            );
        }
    };
    app()->instance(\App\Agents\Providers\Provider::class, $fakeProvider);

    $service = new \App\Agents\Architect\ArchitectService(
        app(\App\Agents\Providers\ProviderRegistry::class),
        app(\App\Projects\Memory\MemoryStore::class),
        app(\App\Projects\Repositories\RepoIndex::class)
    );

    $service->converse($project, 'Hello');

    expect($fakeProvider::$lastRequest->model)->toBe('custom/model');
});
