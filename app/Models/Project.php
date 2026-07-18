<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use App\Enums\CapabilityLevel;
use App\Enums\ProjectStatus;
use App\Enums\QuestionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'test_command',
        'archived_at',
        'workflow_id',
        'confirm_commits',
        'capability_level',
        'actor_budgets',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProjectStatus::class,
            'last_activity_at' => 'datetime',
            'archived_at' => 'datetime',
            'confirm_commits' => 'boolean',
            'capability_level' => CapabilityLevel::class,
            'actor_budgets' => 'array',
        ];
    }

    /**
     * Per-actor daily spend budget for this project (M14b), or null when the
     * actor is excluded/uncapped. Shape: ['daily_cap_usd' => float, 'backup' =>
     * '<role>'|null]. Enforcement lives in App\Core\Usage\SpendGuard.
     *
     * @return array{daily_cap_usd?: float, backup?: ?string}|null
     */
    public function actorBudget(string $role): ?array
    {
        $budgets = $this->actor_budgets ?? [];
        $entry = $budgets[$role] ?? null;

        return is_array($entry) ? $entry : null;
    }

    /**
     * The Architect's granted repository-access tier (M14b opt-in rights),
     * defaulting to Read when unset. Use this — never the raw column — so a null
     * always resolves to the safe default.
     */
    public function capability(): CapabilityLevel
    {
        return $this->capability_level ?? CapabilityLevel::Read;
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
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

    public function executions(): HasMany
    {
        return $this->hasMany(Execution::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }

    public function commitSuggestions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommitSuggestion::class);
    }

    public function openApprovals(): HasMany
    {
        return $this->hasMany(Approval::class)->where('status', ApprovalStatus::Open);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
