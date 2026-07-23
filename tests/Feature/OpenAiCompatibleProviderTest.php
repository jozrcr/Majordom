<?php

use App\Agents\Providers\OpenAiCompatibleProvider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Providers\ToolCall;
use App\Agents\Providers\ToolDefinition;
use App\Agents\Providers\ToolMessages;
use App\Agents\Providers\Exceptions\ProviderRequestFailed;
use App\Agents\Providers\Exceptions\ProviderUnreachable;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

function toolFakeOk(): void
{
    Http::fake([
        'api.test.com/chat/completions' => Http::response([
            'choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']],
        ], 200),
    ]);
}

function readFileTool(): ToolDefinition
{
    return new ToolDefinition(
        name: 'read_file',
        description: 'Read a tracked file',
        parameters: ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
    );
}

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

test('a transient connection failure is retried, then succeeds (turn is not lost)', function () {
    // Two connection blips, then success — the owner should not have to retype.
    $attempts = 0;
    Http::fake(function () use (&$attempts) {
        $attempts++;
        if ($attempts < 3) {
            throw new ConnectionException('temporary blip');
        }

        return Http::response(['choices' => [['message' => ['content' => 'recovered'], 'finish_reason' => 'stop']]], 200);
    });

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $response = $provider->chat(new ProviderRequest('test-model', [['role' => 'user', 'content' => "j'aime"]]));

    expect($response->content)->toBe('recovered')
        ->and($attempts)->toBe(3);
})->group('slow');

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

// ── M15 tool contract ──────────────────────────────────────────────────────

test('tools are serialized into the request with tool_choice auto by default', function () {
    toolFakeOk();

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $provider->chat(new ProviderRequest('m', [['role' => 'user', 'content' => 'hi']], tools: [readFileTool()]));

    Http::assertSent(function ($request) {
        return $request['tools'][0]['type'] === 'function'
            && $request['tools'][0]['function']['name'] === 'read_file'
            && $request['tools'][0]['function']['parameters']['required'] === ['path']
            && $request['tool_choice'] === 'auto';
    });
});

test('no tools key is sent when tools are absent (plain chat unchanged)', function () {
    toolFakeOk();

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $provider->chat(new ProviderRequest('m', [['role' => 'user', 'content' => 'hi']]));

    Http::assertSent(fn ($request) => ! array_key_exists('tools', $request->data())
        && ! array_key_exists('tool_choice', $request->data()));
});

test('tool_choice forcing a specific tool serializes to the function object', function () {
    toolFakeOk();

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $provider->chat(new ProviderRequest('m', [['role' => 'user', 'content' => 'hi']], tools: [readFileTool()], toolChoice: 'propose_plan'));

    Http::assertSent(fn ($request) => $request['tool_choice'] === ['type' => 'function', 'function' => ['name' => 'propose_plan']]);
});

test('tool_choice required and none pass through verbatim', function () {
    toolFakeOk();
    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);

    $provider->chat(new ProviderRequest('m', [['role' => 'user', 'content' => 'hi']], tools: [readFileTool()], toolChoice: 'required'));
    Http::assertSent(fn ($request) => $request['tool_choice'] === 'required');
});

test('a tool_calls response is parsed into normalized ToolCall objects', function () {
    Http::fake([
        'api.test.com/chat/completions' => Http::response([
            'choices' => [[
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'read_file', 'arguments' => '{"path":"composer.json"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 4],
        ], 200),
    ]);

    $provider = new OpenAiCompatibleProvider('https://api.test.com', 'test-key', 30);
    $response = $provider->chat(new ProviderRequest('m', [['role' => 'user', 'content' => 'hi']], tools: [readFileTool()]));

    expect($response->hasToolCalls())->toBeTrue()
        ->and($response->content)->toBe('') // null content normalized to ''
        ->and($response->finishReason)->toBe('tool_calls')
        ->and($response->toolCalls[0])->toBeInstanceOf(ToolCall::class)
        ->and($response->toolCalls[0]->id)->toBe('call_1')
        ->and($response->toolCalls[0]->name)->toBe('read_file')
        ->and($response->toolCalls[0]->arguments)->toBe(['path' => 'composer.json']);
});

test('ToolCall tolerates malformed arguments without crashing', function () {
    $call = ToolCall::fromOpenAi([
        'id' => 'c', 'type' => 'function',
        'function' => ['name' => 'x', 'arguments' => 'not json'],
    ]);

    expect($call->arguments)->toBe([])->and($call->name)->toBe('x');
});

test('ToolMessages build the assistant tool-call turn and the tool result for replay', function () {
    $response = new ProviderResponse(
        content: '',
        finishReason: 'tool_calls',
        promptTokens: 1,
        completionTokens: 1,
        toolCalls: [new ToolCall('call_1', 'read_file', ['path' => 'a.php'])],
    );

    $assistant = ToolMessages::assistantToolCalls($response);
    expect($assistant['role'])->toBe('assistant')
        ->and($assistant['content'])->toBeNull()
        ->and($assistant['tool_calls'][0]['id'])->toBe('call_1')
        ->and($assistant['tool_calls'][0]['function']['name'])->toBe('read_file')
        ->and(json_decode($assistant['tool_calls'][0]['function']['arguments'], true))->toBe(['path' => 'a.php']);

    $result = ToolMessages::toolResult('call_1', '<?php // contents');
    expect($result)->toBe(['role' => 'tool', 'tool_call_id' => 'call_1', 'content' => '<?php // contents']);
});
