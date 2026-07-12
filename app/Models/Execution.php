<?php

namespace App\Models;

use App\Enums\ExecutionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Execution extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'status',
        'current_node',
        'profile',
        'spend_cap_usd',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'status' => ExecutionStatus::class,
            'meta' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function nodes(): HasMany
    {
        return $this->hasMany(Node::class)->orderBy('id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    /** SPEC §8: the engine only asks "is this gate blocking under my profile?" */
    public function gateBehavior(string $gate): string
    {
        return config("majordom.profiles.{$this->profile}.{$gate}", 'block');
    }

    public function park(string $reason): void
    {
        $this->status = ExecutionStatus::Parked;
        $this->meta = array_merge($this->meta ?? [], ['parked_reason' => $reason]);
        $this->save();
    }
}
