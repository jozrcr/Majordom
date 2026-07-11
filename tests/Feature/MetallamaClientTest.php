<?php

use App\Runtime\Metallama\MetallamaClient;
use App\Runtime\Metallama\ServerStatus;
use App\Runtime\Metallama\Exceptions\UnknownModel;
use App\Runtime\Metallama\Exceptions\MetallamaUnreachable;
use App\Runtime\Metallama\Exceptions\RequestFailed;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

beforeEach(function () {
    config(['majordom.metallama' => [
        'base_url' => 'http://127.0.0.1:8010',
        'token' => null,
        'timeout' => 10,
    ]]);
});

it('returns only managed models', function () {
    Http::fake([
        '127.0.0.1:8010/api/models' => Http::response([
            'models' => [
                ['id' => 'm1', 'status' => 'online', 'managed' => true],
                ['id' => 'm2', 'status' => 'offline', 'managed' => false],
            ]
        ], 200),
    ]);

    $client = MetallamaClient::fromConfig();
    $models = $client->models();

    expect($models)->toHaveCount(1);
    expect($models[0]->id)->toBe('m1');
    expect($models[0]->status)->toBe(ServerStatus::Online);
});

it('maps status payload fields correctly', function () {
    Http::fake([
        '127.0.0.1:8010/api/models/Qwen/status' => Http::response([
            'id' => 'Qwen',
            'status' => 'starting',
            'port' => 8080,
            'url' => 'http://localhost:8080',
            'context_window' => 131072,
            'load_progress' => 0.5,
            'last_exit' => null,
            'last_log' => 'loading...',
        ], 200),
    ]);

    $client = MetallamaClient::fromConfig();
    $state = $client->status('Qwen');

    expect($state->id)->toBe('Qwen');
    expect($state->status)->toBe(ServerStatus::Starting);
    expect($state->port)->toBe(8080);
    expect($state->contextWindow)->toBe(131072);
    expect($state->loadProgress)->toBe(0.5);
    expect($state->lastLog)->toBe('loading...');
});

it('throws UnknownModel on 404 status', function () {
    Http::fake([
        '127.0.0.1:8010/api/models/Unknown/status' => Http::response(['detail' => 'Unknown model'], 404),
    ]);

    $client = MetallamaClient::fromConfig();
    
    try {
        $client->status('Unknown');
        fail('Expected UnknownModel');
    } catch (UnknownModel $e) {
        expect($e)->toBeInstanceOf(UnknownModel::class);
    }
});

it('returns state from model key on start', function () {
    Http::fake([
        '127.0.0.1:8010/api/models/Qwen/start' => Http::response([
            'ok' => true,
            'model' => ['id' => 'Qwen', 'status' => 'online', 'managed' => true],
        ], 200),
    ]);

    $client = MetallamaClient::fromConfig();
    $state = $client->start('Qwen');

    expect($state->id)->toBe('Qwen');
    expect($state->status)->toBe(ServerStatus::Online);
});

it('falls back to status on 409 start', function () {
    Http::fake([
        '127.0.0.1:8010/api/models/Qwen/start' => Http::response(['detail' => 'Already running'], 409),
        '127.0.0.1:8010/api/models/Qwen/status' => Http::response([
            'id' => 'Qwen', 'status' => 'online', 'managed' => true,
        ], 200),
    ]);

    $client = MetallamaClient::fromConfig();
    $state = $client->start('Qwen');

    expect($state->status)->toBe(ServerStatus::Online);
    Http::assertSentCount(2);
});

it('returns state on stop', function () {
    Http::fake([
        '127.0.0.1:8010/api/models/Qwen/stop' => Http::response([
            'ok' => true,
            'model' => ['id' => 'Qwen', 'status' => 'offline', 'managed' => true],
        ], 200),
    ]);

    $client = MetallamaClient::fromConfig();
    $state = $client->stop('Qwen');

    expect($state->status)->toBe(ServerStatus::Offline);
});

it('throws MetallamaUnreachable on connection failure', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    $client = MetallamaClient::fromConfig();
    
    try {
        $client->status('Qwen');
        fail('Expected MetallamaUnreachable');
    } catch (MetallamaUnreachable $e) {
        expect($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    }
});

it('throws RequestFailed with status code on 500', function () {
    Http::fake([
        '127.0.0.1:8010/api/models/Qwen/status' => Http::response(['detail' => 'boom'], 500),
    ]);

    $client = MetallamaClient::fromConfig();
    
    try {
        $client->status('Qwen');
        fail('Expected RequestFailed');
    } catch (RequestFailed $e) {
        expect($e->statusCode)->toBe(500);
        expect($e->getMessage())->toBe('boom');
    }
});

it('sends Bearer header when token is set', function () {
    config(['majordom.metallama.token' => 'secret-token']);
    Http::fake([
        '127.0.0.1:8010/api/models' => Http::response(['models' => []], 200),
    ]);

    $client = MetallamaClient::fromConfig();
    $client->models();

    Http::assertSent(function ($request) {
        return $request->hasHeader('Authorization', 'Bearer secret-token');
    });
});

it('does not send Authorization header when token is null', function () {
    config(['majordom.metallama.token' => null]);
    Http::fake([
        '127.0.0.1:8010/api/models' => Http::response(['models' => []], 200),
    ]);

    $client = MetallamaClient::fromConfig();
    $client->models();

    Http::assertSent(function ($request) {
        return ! $request->hasHeader('Authorization');
    });
});
