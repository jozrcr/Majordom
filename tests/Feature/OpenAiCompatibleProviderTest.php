<?php

use App\Agents\Providers\OpenAiCompatibleProvider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Providers\Exceptions\ProviderRequestFailed;
use App\Agents\Providers\Exceptions\ProviderUnreachable;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

test('happy path maps response correctly', function () {
    Http::fake([
        'api.test.com/chat/completions' => Http::response([
            'id' => '123',
            'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi there'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
        ], 200),
    ]);

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $request = new ProviderRequest('test-model', [['role' => 'user', 'content' => 'Hello']]);

    $response = $provider->chat($request);

    expect($response)->toBeInstanceOf(ProviderResponse::class)
        ->and($response->content)->toBe('Hi there')
        ->and($response->finishReason)->toBe('stop')
        ->and($response->promptTokens)->toBe(10)
        ->and($response->completionTokens)->toBe(5);
});

test('request shape excludes null parameters', function () {
    Http::fake([
        'api.test.com/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ], 200),
    ]);

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $request = new ProviderRequest('test-model', [['role' => 'user', 'content' => 'Hello']]);

    $provider->chat($request);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.test.com/chat/completions'
            && $request->header('Authorization')[0] === 'Bearer test-key'
            && $request['model'] === 'test-model'
            && $request['messages'] === [['role' => 'user', 'content' => 'Hello']]
            && !array_key_exists('max_tokens', $request->data())
            && !array_key_exists('temperature', $request->data());
    });
});

test('jsonMode adds response_format', function () {
    Http::fake([
        'api.test.com/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ], 200),
    ]);

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $request = new ProviderRequest('test-model', [['role' => 'user', 'content' => 'Hello']], jsonMode: true);

    $provider->chat($request);

    Http::assertSent(function ($request) {
        return $request['response_format']['type'] === 'json_object';
    });
});

test('maxTokens and temperature are included when set', function () {
    Http::fake([
        'api.test.com/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ], 200),
    ]);

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $request = new ProviderRequest('test-model', [['role' => 'user', 'content' => 'Hello']], maxTokens: 100, temperature: 0.7);

    $provider->chat($request);

    Http::assertSent(function ($request) {
        return $request['max_tokens'] === 100 && $request['temperature'] === 0.7;
    });
});

test('402 error throws ProviderRequestFailed with correct status and message', function () {
    Http::fake([
        'api.test.com/chat/completions' => Http::response([
            'error' => ['message' => 'Insufficient credits', 'code' => 402]
        ], 402),
    ]);

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $request = new ProviderRequest('test-model', [['role' => 'user', 'content' => 'Hello']]);

    $this->expectException(ProviderRequestFailed::class);
    try {
        $provider->chat($request);
    } catch (ProviderRequestFailed $e) {
        expect($e->statusCode)->toBe(402);
        expect($e->getMessage())->toContain('Insufficient credits');
        throw $e;
    }
});

test('connection exception throws ProviderUnreachable', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $request = new ProviderRequest('test-model', [['role' => 'user', 'content' => 'Hello']]);

    $this->expectException(ProviderUnreachable::class);
    $provider->chat($request);
});

test('200 with empty choices throws ProviderRequestFailed', function () {
    Http::fake([
        'api.test.com/chat/completions' => Http::response(['choices' => []], 200),
    ]);

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $request = new ProviderRequest('test-model', [['role' => 'user', 'content' => 'Hello']]);

    $this->expectException(ProviderRequestFailed::class);
    try {
        $provider->chat($request);
    } catch (ProviderRequestFailed $e) {
        expect($e->getMessage())->toContain('no choices');
        throw $e;
    }
});

test('extra headers are sent', function () {
    Http::fake([
        'api.test.com/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ], 200),
    ]);

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30, [
        'HTTP-Referer' => 'https://myapp.test',
        'X-Title' => 'Majordom',
    ]);
    $request = new ProviderRequest('test-model', [['role' => 'user', 'content' => 'Hello']]);

    $provider->chat($request);

    Http::assertSent(function ($request) {
        return $request->header('HTTP-Referer')[0] === 'https://myapp.test'
            && $request->header('X-Title')[0] === 'Majordom';
    });
});
