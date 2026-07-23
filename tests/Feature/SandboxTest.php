<?php

use App\Sandbox\Sandbox;
use App\Sandbox\SandboxPolicy;
use App\Sandbox\SandboxUnavailable;
use App\Sandbox\UnavailableSandbox;

it('binds the honest unavailable sandbox by default', function () {
    expect(app(Sandbox::class))->toBeInstanceOf(UnavailableSandbox::class)
        ->and(app(Sandbox::class)->available())->toBeFalse();
});

it('refuses to run a command rather than executing unconfined on the host', function () {
    expect(fn () => app(Sandbox::class)->run('/repo', ['rm', '-rf', '/'], new SandboxPolicy()))
        ->toThrow(SandboxUnavailable::class);
});

it('policy defaults are conservative (no network, bounded time/memory)', function () {
    $p = new SandboxPolicy();

    expect($p->network)->toBeFalse()
        ->and($p->timeoutSeconds)->toBe(120)
        ->and($p->memoryMb)->toBe(1024);
});
