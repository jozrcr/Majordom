<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProviderEndpoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'label', 'driver', 'base_url', 'api_key', 'timeout', 'meta', 'is_builtin',
    ];

    protected $casts = [
        'api_key' => 'encrypted',
        'meta' => 'array',
        'is_builtin' => 'boolean',
    ];

    public static function named(string $name): ?self
    {
        return static::where('name', $name)->first();
    }

    public function resolvedApiKey(): ?string
    {
        if ($this->api_key !== null) {
            return $this->api_key;
        }

        $configPath = $this->meta['api_key_config'] ?? null;
        if ($configPath) {
            return config($configPath);
        }

        return null;
    }

    public function chatBaseUrl(): string
    {
        if ($this->driver === 'metallama') {
            return rtrim($this->base_url, '/') . '/ollama/v1';
        }

        return $this->base_url;
    }
}
