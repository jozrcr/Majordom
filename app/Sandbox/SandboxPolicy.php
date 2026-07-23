<?php

namespace App\Sandbox;

/**
 * The confinement policy for one sandboxed run (M15). Conservative by default:
 * no network, bounded time and memory. A future container/VM backend enforces it.
 */
final readonly class SandboxPolicy
{
    public function __construct(
        public bool $network = false,
        public ?int $timeoutSeconds = 120,
        public ?int $memoryMb = 1024,
    ) {}
}
