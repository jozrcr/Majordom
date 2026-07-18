<?php

namespace App\Enums;

/**
 * Builder Selection (M14b): which class of Builder implements a task.
 *
 * The Architect does not "become" a Builder — it SELECTS one. A task carries a
 * strategy; the build node routes to the matching Builder binding. Whatever
 * model builds, it acts under the Builder role and its output still goes through
 * the Reviewer (role separation is preserved). `hybrid`/`auto` are reserved for
 * later (telemetry-driven selection).
 */
enum ImplementationStrategy: string
{
    case Local = 'local';        // local Qwen — cheap, unlimited retries, the default
    case Frontier = 'frontier';  // a frontier model acting as Builder (bootstrap, security, hard refactors)

    /** The RoleResolver role name a task with this strategy builds under. */
    public function builderRole(): string
    {
        return match ($this) {
            self::Local => 'builder',
            self::Frontier => 'frontier_builder',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local (Qwen)',
            self::Frontier => 'Frontier',
        };
    }

    /** Null / unknown → Local: the safe, cheap default any task falls back to. */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Local;
    }
}
