<?php

namespace App\Sandbox;

/**
 * The honest default until a real sandbox exists (M15). It NEVER runs a command
 * on the host — running unconfined would be the lie the whole seam exists to
 * avoid. `commands` capability is refused at the Settings layer (T-69); this
 * refuses at the execution layer too, so a future caller can't accidentally
 * escalate to unconfined host execution.
 */
final class UnavailableSandbox implements Sandbox
{
    public function available(): bool
    {
        return false;
    }

    public function run(string $repoPath, array $command, SandboxPolicy $policy): SandboxResult
    {
        throw new SandboxUnavailable(
            'No command sandbox is configured. Actor command execution requires a real sandbox '
            .'(container or microVM with the repo bind-mounted) — not yet available. Grant reads, not commands.'
        );
    }
}
