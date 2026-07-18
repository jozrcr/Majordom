<?php

namespace App\Enums;

/**
 * Opt-in actor rights (M14b, crescendo capabilities). An owner grants an actor
 * graduated access to their machine — read-only is the floor, escalating toward
 * "full allowed" — like Claude Code's permission tiers. Set per project in the
 * Project Settings tab.
 *
 * v1 governs the ARCHITECT's repository access: reads (T-66) are a granted
 * capability, not always-on. `Commands` is defined but gated in the UI — true
 * hard enforcement of command execution needs an OS sandbox (bubblewrap /
 * firejail / container, repo-only bind mount), so it is NOT a plain in-process
 * toggle over Process::run. Reads, by contrast, ARE enforceable in-process
 * (realpath confinement + tracked-only, see RepoIndex / ArchitectService).
 */
enum CapabilityLevel: string
{
    case None = 'none';         // no autonomous repo access — the Architect must ask the owner
    case Read = 'read';         // file reads, realpath-confined to the repo, tracked-only (default)
    case Commands = 'commands'; // + run commands (gated until a sandbox exists)

    public function canRead(): bool
    {
        return $this === self::Read || $this === self::Commands;
    }

    public function canRunCommands(): bool
    {
        return $this === self::Commands;
    }

    public function label(): string
    {
        return match ($this) {
            self::None => 'No access',
            self::Read => 'Read files',
            self::Commands => 'Read + run commands',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::None => 'The Architect cannot open repository files on its own — it must ask you to share them.',
            self::Read => 'The Architect may read tracked files in this repo (confined to the repo folder, no secrets). Recommended.',
            self::Commands => 'Also run commands on your machine. Requires a sandbox — not yet available.',
        };
    }

    /** Whether this tier can be selected today (Commands is gated on a sandbox). */
    public function selectable(): bool
    {
        return $this !== self::Commands;
    }

    /** Null / unknown → Read: the safe default the owner is opted into. */
    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::Read;
    }
}
