<?php

namespace App\Agents\Harness;

final readonly class HarnessResult
{
    public function __construct(
        public HarnessStatus $status,
        public string $diff,
        public array $filesChanged,
        public ?bool $testsPassed,
        public string $summary,
        public array $openQuestions,
        public string $rawLog,
    ) {
    }

    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'diff' => $this->diff,
            'files_changed' => $this->filesChanged,
            'tests_passed' => $this->testsPassed,
            'summary' => $this->summary,
            'open_questions' => $this->openQuestions,
            'raw_log' => $this->rawLog,
        ];
    }
}
