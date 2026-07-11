<?php

namespace App\Models;

use App\Enums\MessageRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConsensusMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'role',
        'content',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'role' => MessageRole::class,
            'meta' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
