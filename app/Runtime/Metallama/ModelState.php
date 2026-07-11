<?php

namespace App\Runtime\Metallama;

final readonly class ModelState
{
    public function __construct(
        public string $id,
        public ServerStatus $status,
        public ?int $port = null,
        public ?string $url = null,
        public ?int $contextWindow = null,
        public ?float $loadProgress = null,
        public mixed $lastExit = null,
        public string $lastLog = '',
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            id: $payload['id'] ?? '',
            status: ServerStatus::from($payload['status'] ?? 'offline'),
            port: $payload['port'] ?? null,
            url: $payload['url'] ?? null,
            contextWindow: $payload['context_window'] ?? null,
            loadProgress: $payload['load_progress'] ?? null,
            lastExit: $payload['last_exit'] ?? null,
            lastLog: $payload['last_log'] ?? '',
        );
    }

    public function isOnline(): bool
    {
        return $this->status === ServerStatus::Online;
    }
}
