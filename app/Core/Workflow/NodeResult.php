<?php

namespace App\Core\Workflow;

use App\Enums\ApprovalType;

/**
 * What a node run produced. Three shapes: done (advance the chain),
 * waitHuman (park this node behind an Approval — the M4 inbox item),
 * failed (park the whole execution, visibly).
 */
final readonly class NodeResult
{
    private function __construct(
        public string $status, // done | waiting | failed | retry
        public array $output,
        public ?ApprovalType $approvalType = null,
        public string $approvalTitle = '',
        public array $approvalPayload = [],
        public string $failureReason = '',
        /** @var string[] node types to reset to pending, in-chain, incl. self */
        public array $retryResets = [],
    ) {}

    public static function done(array $output = []): self
    {
        return new self('done', $output);
    }

    public static function waitHuman(ApprovalType $type, string $title, array $payload = [], array $output = []): self
    {
        return new self('waiting', $output, $type, $title, $payload);
    }

    public static function failed(string $reason, array $output = []): self
    {
        return new self('failed', $output, failureReason: $reason);
    }

    /**
     * The bounded revise loop (SPEC §3 phases 6-7): reset the named node
     * types (and this node) to pending so the chain re-runs them — the
     * revision brief carries the why.
     */
    public static function retry(array $resetTypes, string $reason, array $output = []): self
    {
        return new self('retry', $output, failureReason: $reason, retryResets: $resetTypes);
    }
}
