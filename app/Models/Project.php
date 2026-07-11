<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'repo_path',
        'memory_path',
        'status',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'last_activity_at' => 'datetime',
        ];
    }
}
