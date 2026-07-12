<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageRecord extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'project_id',
        'execution_id',
        'role',
        'model',
        'prompt_tokens',
        'completion_tokens',
        'cost_usd',
        'created_at',
    ];

    protected $casts = [
        'cost_usd' => 'float',
        'created_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();
        static::creating(function ($model) {
            if (!$model->created_at) {
                $model->created_at = now();
            }
        });
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
