<?php

namespace App\Agents\Reviewer;

/**
 * The single result of a milestone review (M15). One of:
 *  - approved — the milestone's cumulative work satisfies its goal
 *  - changes  — specific, keyed deficiencies to fix (become new fix-tasks)
 *  - escalate — an owner-level ambiguity, or the convergence guard tripped
 */
final readonly class MilestoneReviewOutcome
{
    /**
     * @param array<int, array{task_key: ?string, file: ?string, reason: string}> $items
     * @param string[] $questions
     * @param ?string $howToTest  On approval: concrete steps the owner can run to
     *                            verify this milestone end-to-end (M16-A merge gate).
     */
    public function __construct(
        public string $type,
        public string $summary = '',
        public array $items = [],
        public array $questions = [],
        public ?string $howToTest = null,
    ) {}

    public function isApproved(): bool
    {
        return $this->type === 'approved';
    }

    public function isChanges(): bool
    {
        return $this->type === 'changes';
    }

    public function isEscalate(): bool
    {
        return $this->type === 'escalate';
    }
}
