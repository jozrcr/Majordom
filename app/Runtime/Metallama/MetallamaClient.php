<?php

namespace App\Runtime\Metallama;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use App\Runtime\Metallama\Exceptions\MetallamaUnreachable;
use App\Runtime\Metallama\Exceptions\UnknownModel;
use App\Runtime\Metallama\Exceptions\RequestFailed;

class MetallamaClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $token,
        private readonly int $timeout
    ) {}

    public static function fromConfig(): self
    {
        $config = config('majordom.metallama');
        return new self(
            baseUrl: $config['base_url'],
            token: $config['token'],
            timeout: $config['timeout']
        );
    }

    private function http()
    {
        $request = Http::baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->acceptJson();

        if ($this->token !== null && $this->token !== '') {
            $request->withToken($this->token);
        }

        return $request;
    }

    public function models(): array
    {
        $response = $this->request('GET', '/api/models');
        return array_values(array_map(
            fn ($m) => ModelState::fromArray($m),
            array_filter($response['models'] ?? [], fn ($m) => ($m['managed'] ?? false) === true)
        ));
    }

    public function status(string $id): ModelState
    {
        $response = $this->request('GET', "/api/models/{$id}/status");
        return ModelState::fromArray($response);
    }

    public function start(string $id): ModelState
    {
        try {
            $response = $this->request('POST', "/api/models/{$id}/start");
            return ModelState::fromArray($response['model']);
        } catch (RequestFailed $e) {
            if ($e->statusCode === 409) {
                return $this->status($id);
            }
            throw $e;
        }
    }

    public function stop(string $id): ModelState
    {
        $response = $this->request('POST', "/api/models/{$id}/stop");
        return ModelState::fromArray($response['model']);
    }

    private function request(string $method, string $url): array
    {
        try {
            $response = $this->http()->{$method}($url);
        } catch (ConnectionException $e) {
            throw new MetallamaUnreachable('Metallama is unreachable.', 0, $e);
        }

        if ($response->status() === 404) {
            throw new UnknownModel('Unknown model.');
        }

        if ($response->failed()) {
            $detail = $response->json('detail', 'Request failed.');
            // FastAPI sometimes nests detail as an object.
            if (! is_string($detail)) {
                $detail = json_encode($detail) ?: 'Request failed.';
            }
            throw new RequestFailed($response->status(), $detail);
        }

        return $response->json();
    }
}
