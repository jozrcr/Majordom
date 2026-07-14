<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Milestone extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'milestone_key',
        'title',
        'summary',
        'position',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class)->orderBy('position');
    }

    public function deriveStatus(): string
    {
        $tasks = $this->relationLoaded('tasks') ? $this->tasks : $this->tasks;
        if ($tasks->isEmpty()) {
            return 'todo';
        }

        $hasDone = false;
        $hasOngoing = false;

        foreach ($tasks as $task) {
            $status = \App\Projects\Roadmap\RoadmapSync::effectiveStatus($task);
            if ($status === 'done') {
                $hasDone = true;
            } elseif ($status === 'ongoing') {
                $hasOngoing = true;
            }
        }

        if ($hasDone && !$hasOngoing) {
            return 'done';
        }
        if ($hasOngoing) {
            return 'ongoing';
        }
        return 'todo';
    }
}
