<?php

namespace App\Sandbox;

/**
 * The command-execution boundary (M15 tool-contract §Sandbox seam), named now,
 * implemented later — exactly like the Harness lives behind its interface.
 *
 * Any command an actor runs on the owner's behalf (the `commands` capability tier,
 * and eventually the Builder's git/test runs) must go through here so it can be
 * confined: ONLY the repo mounted, a network/resource/time policy, disposable
 * blast radius. In-process `Process::run` CANNOT provide that, so there is no
 * in-process implementation — the honest default (UnavailableSandbox) refuses.
 *
 * Planned implementations: v1 a container (Podman/Docker, repo-only mount,
 * controlled network, resource limits, the target repo's toolchain in the image);
 * endgame a disposable microVM / cloud VM (the owner's "much more control than on
 * our machine" — where "full allowed" becomes an honest choice, not a lie over a
 * shell). Validated work returns to the real repo only through the human merge gate.
 */
interface Sandbox
{
    /** True when a real sandbox backs this seam (a container/VM is available). */
    public function available(): bool;

    /**
     * Run $command with ONLY $repoPath mounted, under $policy. Implementations
     * MUST NOT fall back to unconfined host execution.
     *
     * @param string[] $command
     */
    public function run(string $repoPath, array $command, SandboxPolicy $policy): SandboxResult;
}
