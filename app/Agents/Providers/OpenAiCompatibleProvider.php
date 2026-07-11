<?php

namespace App\Agents\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use App\Agents\Providers\Exceptions\ProviderUnreachable;
use App\Agents\Providers\Exceptions\ProviderRequestFailed;

class OpenAiCompatibleProvider implements Provider
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey,
        private readonly int $timeout,
        private readonly array $extraHeaders = []
    ) {}

    public static function openrouter(): self
    {
        $config = config('majordom.providers.openrouter');
        return new self(
            baseUrl: $config['base_url'],
            apiKey: $config['api_key'],
            timeout: $config['timeout'],
            extraHeaders: [
                'HTTP-Referer' => config('app.url'),
                'X-Title' => 'Majordom',
            ]
        );
    }

    public function chat(ProviderRequest $request): ProviderResponse
    {
        $body = [
            'model' => $request->model,
            'messages' => $request->messages,
        ];

        if ($request->maxTokens !== null) {
            $body['max_tokens'] = $request->maxTokens;
        }

        if ($request->temperature !== null) {
            $body['temperature'] = $request->temperature;
        }

        if ($request->jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout($this->timeout)
                ->acceptJson()
                ->withToken($this->apiKey)
                ->withHeaders($this->extraHeaders)
                ->post('/chat/completions', $body);
        } catch (ConnectionException $e) {
            throw new ProviderUnreachable('Failed to connect to provider.', 0, $e);
        }

        if (!$response->successful()) {
            $json = $response->json();
            $message = is_array($json) ? ($json['error']['message'] ?? null) : null;
            
            if (!$message) {
                $raw = $response->body();
                $message = is_string($raw) ? $raw : json_encode($raw);
                $message = mb_substr($message, 0, 300);
            }

            throw new ProviderRequestFailed($message, $response->status());
        }

        $json = $response->json();
        if (empty($json['choices'])) {
            throw new ProviderRequestFailed('Provider returned no choices.', $response->status());
        }

        return ProviderResponse::fromOpenAi($json);
    }
}
