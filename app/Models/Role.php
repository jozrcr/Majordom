<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Role extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'name',
        'provider',
        'model',
        'meta',
        'temperature',
        'max_tokens',
        'is_builtin',
    ];

    protected $casts = [
        'meta' => 'array',
        'temperature' => 'float',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
