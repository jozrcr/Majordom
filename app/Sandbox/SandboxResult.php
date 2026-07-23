<?php

namespace App\Sandbox;

/** The result of one sandboxed command run (M15). */
final readonly class SandboxResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput = '',
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
