<?php

namespace App\Agents\Harness;

final readonly class HarnessRequest
{
    public function __construct(
        public string $repoPath,
        public string $endpointBaseUrl,
        public string $modelName,
        public string $rolePrompt,
        public string $taskPrompt,
        public ?string $testCommand = null,
        public array $fileHints = [],
        public ?int $timeoutSeconds = null,
    ) {
    }
}
