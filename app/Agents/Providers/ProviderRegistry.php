<?php

namespace App\Agents\Providers;

class ProviderRegistry
{
    /** Resolve the concrete Provider for a role binding. */
    public function forBinding(\App\Support\RoleBinding $binding): Provider
    {
        return $this->forName($binding->provider);
    }

    public function forName(string $name): Provider
    {
        if (app()->bound(Provider::class)) {
            return app(Provider::class);
        }

        $endpoint = \App\Models\ProviderEndpoint::named($name);
        if (! $endpoint) {
            throw new \InvalidArgumentException("Unknown provider endpoint: {$name}");
        }

        return new OpenAiCompatibleProvider(
            baseUrl: $endpoint->chatBaseUrl(),
            apiKey: $endpoint->resolvedApiKey(),
            timeout: $endpoint->timeout,
            extraHeaders: array_merge(
                ['HTTP-Referer' => config('app.url'), 'X-Title' => 'Majordom'],
                $endpoint->meta['extra_headers'] ?? [],
            ),
        );
    }
}
