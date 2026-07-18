<?php

namespace App\Models;

use App\Enums\ImplementationStrategy;
use App\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'execution_id',
        'task_key',
        'title',
        'branch',
        'worktree_path',
        'status',
        'revision',
        'base_commit',
        'clarified_at_revision',
        'milestone_id',
        'position',
        'declared_status',
        'description',
        'implementation_strategy',
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
            'implementation_strategy' => ImplementationStrategy::class,
        ];
    }

    /**
     * Builder Selection (M14b): the strategy this task builds under, defaulting
     * to Local when unset. Use this (never the raw column) for routing so a null
     * always resolves to the safe, cheap default.
     */
    public function strategy(): ImplementationStrategy
    {
        return $this->implementation_strategy ?? ImplementationStrategy::Local;
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function milestone(): BelongsTo
    {
        return $this->belongsTo(Milestone::class);
    }
}
