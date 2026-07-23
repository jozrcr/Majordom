<?php

use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Core\Usage\UsageLedger;
use App\Models\Project;
use App\Models\UsageRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class)->group('usage');

beforeEach(function () {
    Cache::flush();
    config(['majordom.providers.openrouter.base_url' => 'https://openrouter.ai/api']);
    config(['majordom.providers.openrouter.api_key' => 'test-key']);
    config(['majordom.builder.gateway_model' => 'local/gateway']);
});

test('record inserts with computed cost', function () {
    Http::fake([
        '*openrouter*/models' => Http::response([
            'data' => [
                [
                    'id' => 'deepseek/deepseek-v4-flash',
                    'pricing' => ['prompt' => '0.0000002', 'completion' => '0.0000008'],
                ],
            ],
        ], 200),
    ]);

    $project = Project::factory()->create();
    $ledger = app(UsageLedger::class);
    
    $ledger->record($project, 'architect', 'deepseek/deepseek-v4-flash', 1000, 500);

    $record = UsageRecord::first();
    expect($record)->not->toBeNull()
        ->role->toBe('architect')
        ->model->toBe('deepseek/deepseek-v4-flash')
        ->prompt_tokens->toBe(1000)
        ->completion_tokens->toBe(500);
        
    $expectedCost = 1000 * 0.0000002 + 500 * 0.0000008;
    expect(round($record->cost_usd, 8))->toBe(round($expectedCost, 8));
});

test('unknown frontier model yields null cost', function () {
    Http::fake([
        '*openrouter*/models' => Http::response(['data' => []], 200),
    ]);

    $project = Project::factory()->create();
    app(UsageLedger::class)->record($project, 'reviewer', 'unknown/model', 100, 50);

    $record = UsageRecord::first();
    expect($record->cost_usd)->toBeNull();
});

test('gateway or local model yields 0.0 cost', function () {
    $project = Project::factory()->create();
    app(UsageLedger::class)->record($project, 'builder', 'local/gateway', 100, 50);
    
    $record = UsageRecord::first();
    expect($record->cost_usd)->toBe(0.0);
    
    app(UsageLedger::class)->record($project, 'builder', 'llama3', 100, 50);
    $localRecord = UsageRecord::where('model', 'llama3')->first();
    expect($localRecord->cost_usd)->toBe(0.0);
});

test('pricing fetch failure still inserts with null cost and no exception', function () {
    Http::fake([
        '*openrouter*/models' => Http::response(null, 500),
    ]);

    $project = Project::factory()->create();
    app(UsageLedger::class)->record($project, 'architect', 'deepseek/deepseek-v4-flash', 100, 50);

    $record = UsageRecord::first();
    expect($record)->not->toBeNull()
        ->cost_usd->toBeNull();
});

test('ledger never throws even if table is missing', function () {
    Schema::dropIfExists('usage_records');
    
    $project = Project::factory()->create();
    app(UsageLedger::class)->record($project, 'architect', 'test/model', 100, 50);
    
    expect(true)->toBeTrue();
});

test('parseAiderTokens extracts correct values', function () {
    expect(UsageLedger::parseAiderTokens('Tokens: 832 sent, 210 received.'))->toBe([832, 210]);
    expect(UsageLedger::parseAiderTokens('Tokens: 3.7k sent, 1.2k received.'))->toBe([3700, 1200]);
    expect(UsageLedger::parseAiderTokens('Tokens: 100 sent, 50 received.\nTokens: 200 sent, 100 received.'))->toBe([200, 100]);
    expect(UsageLedger::parseAiderTokens('garbage log'))->toBe([0, 0]);
});

test('ArchitectService turn records an architect row', function () {
    Http::fake([
        '*openrouter*/models' => Http::response(['data' => []], 200),
    ]);

    $project = Project::factory()->create();

    $mockProvider = new class implements Provider {
        public function chat(ProviderRequest $request): ProviderResponse
        {
            // A terminating tool call (ask_owner) — one turn, one usage row.
            return new ProviderResponse(
                content: '',
                finishReason: 'tool_calls',
                promptTokens: 150,
                completionTokens: 50,
                toolCalls: [new \App\Agents\Providers\ToolCall('c', 'ask_owner', ['questions' => [['text' => 'Which stack?']]])],
            );
        }
    };

    app()->instance(\App\Agents\Providers\Provider::class, $mockProvider);
    $service = new ArchitectService(app(\App\Agents\Providers\ProviderRegistry::class), app(\App\Projects\Memory\MemoryStore::class), app(\App\Projects\Repositories\RepoIndex::class));
    $service->converse($project, 'Hello');

    expect(UsageRecord::where('role', 'architect')->count())->toBe(1)
        ->and(UsageRecord::first()->prompt_tokens)->toBe(150)
        ->and(UsageRecord::first()->completion_tokens)->toBe(50);
});
