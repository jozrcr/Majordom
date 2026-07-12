<?php

namespace App\Models;

use App\Enums\ProjectStatus;
use App\Enums\QuestionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function consensusMessages(): HasMany
    {
        return $this->hasMany(ConsensusMessage::class);
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class);
    }

    public function openQuestions(): HasMany
    {
        return $this->hasMany(Question::class)->where('status', QuestionStatus::Open);
    }
}
