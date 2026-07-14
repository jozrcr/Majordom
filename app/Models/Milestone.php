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
        $tasks = $this->tasks;
        if ($tasks->isEmpty()) {
            return 'todo';
        }

        $statuses = $tasks->map(
            fn (Task $task) => \App\Projects\Roadmap\RoadmapSync::effectiveStatus($task)
        );

        // All done → done; none started (all todo) → todo; any mix or any
        // ongoing → ongoing. A single unfinished task keeps the milestone open.
        if ($statuses->every(fn (string $s) => $s === 'done')) {
            return 'done';
        }
        if ($statuses->every(fn (string $s) => $s === 'todo')) {
            return 'todo';
        }

        return 'ongoing';
    }
}
