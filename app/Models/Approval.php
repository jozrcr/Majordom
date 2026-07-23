<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'execution_id',
        'type',
        'title',
        'payload',
        'status',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ApprovalType::class,
            'status' => ApprovalStatus::class,
            'payload' => 'array',
            'resolved_at' => 'datetime',
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

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Open);
    }

    public function grant(): void
    {
        $this->status = ApprovalStatus::Granted;
        $this->resolved_at = now();
        $this->save();
    }

    public function reject(): void
    {
        $this->status = ApprovalStatus::Rejected;
        $this->resolved_at = now();
        $this->save();
    }

    /** M16-A: set aside a milestone merge without closing it — the branch and
     *  worktree stay intact and the owner can merge it later. */
    public function defer(): void
    {
        $this->status = ApprovalStatus::Deferred;
        $this->resolved_at = now();
        $this->save();
    }

    public function scopeDeferred(Builder $query): Builder
    {
        return $query->where('status', ApprovalStatus::Deferred);
    }
}
