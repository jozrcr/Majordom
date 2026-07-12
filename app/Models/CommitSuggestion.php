<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A prepared commit (message + the diff it covers). NEVER auto-applied —
 * commit/push authority stays with the human (PHILOSOPHY §2).
 */
class CommitSuggestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'execution_id',
        'task_id',
        'message',
        'diff',
        'branch',
        'status',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
