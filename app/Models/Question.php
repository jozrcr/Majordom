<?php

namespace App\Models;

use App\Enums\QuestionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'consensus_message_id',
        'execution_id',
        'text',
        'options',
        'status',
        'answer',
        'answered_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => QuestionStatus::class,
            'options' => 'array',
            'answered_at' => 'datetime',
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

    public function consensusMessage(): BelongsTo
    {
        return $this->belongsTo(ConsensusMessage::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', QuestionStatus::Open);
    }

    public function answerWith(string $answer): void
    {
        $this->update([
            'answer' => $answer,
            'status' => QuestionStatus::Answered,
            'answered_at' => now(),
        ]);
    }
}
