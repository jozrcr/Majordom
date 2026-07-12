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
        public string $status, // done | waiting | failed
        public array $output,
        public ?ApprovalType $approvalType = null,
        public string $approvalTitle = '',
        public array $approvalPayload = [],
        public string $failureReason = '',
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
}
