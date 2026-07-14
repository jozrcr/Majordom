<?php

namespace App\Projects\Metrics;

use App\Models\Event;
use App\Models\Milestone;
use App\Models\Task;
use App\Models\UsageRecord;
use Illuminate\Support\Collection;

class MilestoneMetrics
{
    private const HUMAN_EVENTS = [
        'approval.granted',
        'question.answered',
        'human_review.waiting_human',
        'human_task.waiting_human',
    ];

    private const REWORK_EVENTS = [
        'review.retry',
        'review.failed',
    ];

    public static function forMilestone(Milestone $m): array
    {
        $execIds = $m->tasks->pluck('execution_id')->filter()->unique()->values();
        return self::compute($execIds);
    }

    public static function forTask(Task $t): array
    {
        $execIds = $t->execution_id ? collect([$t->execution_id]) : collect();
        $metrics = self::compute($execIds);

        $revisionRework = max(0, (int) ($t->revision ?? 0) - 1);
        $metrics['rework_cycles'] = max($metrics['rework_cycles'], $revisionRework);

        return $metrics;
    }

    public static function forTasks(Collection $tasks): array
    {
        $byExecId = $tasks->filter(fn($t) => $t->execution_id)->groupBy('execution_id');
        $results = [];

        foreach ($byExecId as $execId => $execTasks) {
            $metrics = self::compute(collect([$execId]));
            foreach ($execTasks as $t) {
                $m = $metrics;
                $revisionRework = max(0, (int) ($t->revision ?? 0) - 1);
                $m['rework_cycles'] = max($m['rework_cycles'], $revisionRework);
                $results[$t->id] = $m;
            }
        }

        foreach ($tasks as $t) {
            if (!isset($results[$t->id])) {
                $results[$t->id] = self::emptyMetrics();
            }
        }

        return $results;
    }

    private static function compute(Collection $execIds): array
    {
        if ($execIds->isEmpty()) {
            return self::emptyMetrics();
        }

        $usage = UsageRecord::whereIn('execution_id', $execIds)
            ->selectRaw('role, SUM(prompt_tokens + completion_tokens) as total_tokens, SUM(cost_usd) as total_cost')
            ->groupBy('role')
            ->get();

        $tokens = ['architect' => 0, 'builder' => 0, 'reviewer' => 0];
        $cost = 0.0;
        foreach ($usage as $row) {
            $role = strtolower($row->role);
            if (array_key_exists($role, $tokens)) {
                $tokens[$role] = (int) ($row->total_tokens ?? 0);
            }
            $cost += (float) ($row->total_cost ?? 0);
        }

        $events = Event::whereIn('execution_id', $execIds)->get();
        $humanInterventions = $events->whereIn('name', self::HUMAN_EVENTS)->count();
        $reworkCycles = $events->whereIn('name', self::REWORK_EVENTS)->count();

        $changedFiles = [];
        foreach ($events as $event) {
            if ($event->name === 'build.completed' && isset($event->payload['filesChanged'])) {
                $changedFiles = array_merge($changedFiles, (array) ($event->payload['filesChanged'] ?? []));
            }
        }
        $filesChanged = count(array_unique($changedFiles));

        $timeToCompletion = null;
        if ($events->isNotEmpty()) {
            $minTime = $events->min('created_at');
            $maxTime = $events->max('created_at');
            if ($minTime && $maxTime) {
                $timeToCompletion = $minTime->diffInSeconds($maxTime);
            }
        }

        return [
            'tokens' => $tokens,
            'cost_usd' => $cost,
            'human_interventions' => $humanInterventions,
            'rework_cycles' => $reworkCycles,
            'files_changed' => $filesChanged,
            'time_to_completion' => $timeToCompletion,
            'tests_added' => null,
        ];
    }

    private static function emptyMetrics(): array
    {
        return [
            'tokens' => ['architect' => 0, 'builder' => 0, 'reviewer' => 0],
            'cost_usd' => 0.0,
            'human_interventions' => 0,
            'rework_cycles' => 0,
            'files_changed' => 0,
            'time_to_completion' => null,
            'tests_added' => null,
        ];
    }
}
