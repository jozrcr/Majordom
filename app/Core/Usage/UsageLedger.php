<?php

namespace App\Core\Usage;

use App\Models\Execution;
use App\Models\Project;
use App\Models\UsageRecord;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class UsageLedger
{
    public function record(
        Project $project,
        string $role,
        string $model,
        int $promptTokens,
        int $completionTokens,
        ?Execution $execution = null
    ): void {
        try {
            $cost = $this->costFor($model, $promptTokens, $completionTokens);
            
            UsageRecord::create([
                'project_id' => $project->id,
                'execution_id' => $execution?->id,
                'role' => $role,
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cost_usd' => $cost,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function costFor(string $model, int $prompt, int $completion): ?float
    {
        if (str_contains($model, '/') === false || $model === config('majordom.builder.gateway_model')) {
            return 0.0;
        }

        $map = $this->pricingMap();
        $pricing = $map[$model] ?? null;

        if ($pricing === null) {
            return null;
        }

        return ($pricing['prompt'] * $prompt) + ($pricing['completion'] * $completion);
    }

    protected function pricingMap(): array
    {
        $cached = Cache::get('openrouter-pricing');
        if ($cached !== null) {
            return $cached;
        }

        try {
            $url = config('majordom.providers.openrouter.base_url') . '/models';
            $response = Http::timeout(10)->get($url);
            
            if (!$response->successful()) {
                throw new \RuntimeException('Failed to fetch pricing');
            }

            $data = $response->json('data');
            $map = [];
            foreach ($data as $m) {
                $id = $m['id'] ?? null;
                $pricing = $m['pricing'] ?? null;
                if ($id && $pricing) {
                    $map[$id] = [
                        'prompt' => (float) ($pricing['prompt'] ?? 0),
                        'completion' => (float) ($pricing['completion'] ?? 0),
                    ];
                }
            }
            Cache::put('openrouter-pricing', $map, now()->addDay());
            return $map;
        } catch (\Throwable $e) {
            Cache::put('openrouter-pricing', [], now()->addMinutes(5));
            return [];
        }
    }

    public static function parseAiderTokens(string $rawLog): array
    {
        $pattern = '/Tokens:\s*([\d.,]+k?)\s*sent,\s*([\d.,]+k?)\s*received/i';
        if (preg_match_all($pattern, $rawLog, $matches, PREG_SET_ORDER)) {
            $last = end($matches);
            $sent = self::parseTokenValue($last[1]);
            $received = self::parseTokenValue($last[2]);
            return [(int) $sent, (int) $received];
        }
        return [0, 0];
    }

    private static function parseTokenValue(string $value): float
    {
        $value = str_replace(',', '', $value);
        if (str_ends_with(strtolower($value), 'k')) {
            return (float) rtrim($value, 'k') * 1000;
        }
        return (float) $value;
    }
}
