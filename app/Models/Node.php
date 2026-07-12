<?php

namespace App\Models;

use App\Enums\NodeStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Node extends Model
{
    use HasFactory;

    protected $fillable = [
        'execution_id',
        'type',
        'status',
        'input',
        'output',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => NodeStatus::class,
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(Execution::class);
    }

    public function start(): void
    {
        $this->status = NodeStatus::Running;
        $this->started_at = now();
        $this->save();
    }

    public function finish(array $output): void
    {
        $this->status = NodeStatus::Completed;
        $this->output = $output;
        $this->finished_at = now();
        $this->save();
    }

    public function fail(array $output): void
    {
        $this->status = NodeStatus::Failed;
        $this->output = $output;
        $this->finished_at = now();
        $this->save();
    }
}
