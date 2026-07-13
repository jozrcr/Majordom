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

    /**
     * Built-in rows carry meta.base_url_config so the URL keeps tracking
     * config/env at runtime (same indirection as api_key_config) instead of
     * the value snapshotted at migration time. Custom rows store a literal
     * base_url and have no meta pointer, so the column wins.
     */
    public function resolvedBaseUrl(): string
    {
        $configPath = $this->meta['base_url_config'] ?? null;
        if ($configPath && ($url = config($configPath))) {
            return $url;
        }

        return $this->base_url;
    }

    public function chatBaseUrl(): string
    {
        $base = $this->resolvedBaseUrl();

        if ($this->driver === 'metallama') {
            return rtrim($base, '/') . '/ollama/v1';
        }

        return $base;
    }
}
