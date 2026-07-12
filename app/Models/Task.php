<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'status' => TaskStatus::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }
}
