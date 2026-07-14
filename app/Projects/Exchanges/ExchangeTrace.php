<?php

namespace App\Projects\Exchanges;

use App\Models\Execution;
use Illuminate\Support\Str;

class ExchangeTrace
{
    public static function for(Execution $execution): array
    {
        $events = \App\Models\Event::where('execution_id', $execution->id)->orderBy('id')->get();
        $rows = [];
        $instructionEmitted = false;

        $taskDescription = $execution->tasks()
            ->whereNotNull('description')
            ->value('description') ?? '(brief pending)';

        foreach ($events as $event) {
            $payload = $event->payload ?? [];
            $name = $event->name;

            $exchange = match (true) {
                ($name === 'task.delegated' || $name === 'delegate.started') && !$instructionEmitted => [
                    'from' => 'architect',
                    'to' => 'builder',
                    'kind' => 'instruction',
                    'full' => $taskDescription,
                    'at' => $event->created_at,
                ],
                $name === 'build.completed' => [
                    'from' => 'builder',
                    'to' => 'reviewer',
                    'kind' => 'result',
                    'full' => ($payload['summary'] ?? '') . "\nFiles: " . implode(', ', $payload['filesChanged'] ?? []),
                    'at' => $event->created_at,
                ],
                $name === 'build.failed' => [
                    'from' => 'builder',
                    'to' => 'owner',
                    'kind' => 'failure',
                    'full' => $payload['summary'] ?? $payload['reason'] ?? 'Build failed',
                    'at' => $event->created_at,
                ],
                $name === 'test.completed' => [
                    'from' => 'tests',
                    'to' => 'reviewer',
                    'kind' => 'result',
                    'full' => !empty($payload) ? json_encode($payload) : 'Tests ran',
                    'at' => $event->created_at,
                ],
                $name === 'review.completed' => [
                    'from' => 'reviewer',
                    'to' => 'builder',
                    'kind' => 'verdict',
                    'full' => $payload['verdict'] ?? $payload['reason'] ?? 'Approved',
                    'at' => $event->created_at,
                ],
                $name === 'review.retry' || $name === 'review.failed' => [
                    'from' => 'reviewer',
                    'to' => 'builder',
                    'kind' => 'rework',
                    'full' => $payload['reason'] ?? 'Changes requested',
                    'at' => $event->created_at,
                ],
                $name === 'human_review.waiting_human' => [
                    'from' => 'reviewer',
                    'to' => 'owner',
                    'kind' => 'clarification',
                    'full' => 'Awaiting human review',
                    'at' => $event->created_at,
                ],
                $name === 'consensus.message' => [
                    'from' => 'architect',
                    'to' => 'owner',
                    'kind' => 'consensus',
                    'full' => $payload['excerpt'] ?? '(consensus turn)',
                    'at' => $event->created_at,
                ],
                $name === 'question.answered' => [
                    'from' => 'owner',
                    'to' => 'architect',
                    'kind' => 'clarification',
                    'full' => $payload['answer'] ?? '(answered)',
                    'at' => $event->created_at,
                ],
                $name === 'commit.applied' => [
                    'from' => 'owner',
                    'to' => 'system',
                    'kind' => 'commit',
                    'full' => $payload['message'] ?? 'Committed',
                    'at' => $event->created_at,
                ],
                default => null,
            };

            if ($exchange !== null) {
                if ($exchange['kind'] === 'instruction') {
                    $instructionEmitted = true;
                }
                $exchange['excerpt'] = Str::limit($exchange['full'], 200, '…');
                $rows[] = $exchange;
            }
        }

        return $rows;
    }

    public static function usageFor(Execution $execution): array
    {
        return \App\Models\UsageRecord::where('execution_id', $execution->id)
            ->selectRaw('role, SUM(prompt_tokens) as prompt_tokens, SUM(completion_tokens) as completion_tokens, SUM(cost_usd) as cost_usd')
            ->groupBy('role')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->role => [
                'prompt_tokens' => (int) $r->prompt_tokens,
                'completion_tokens' => (int) $r->completion_tokens,
                'cost_usd' => (float) $r->cost_usd,
            ]])
            ->toArray();
    }
}
