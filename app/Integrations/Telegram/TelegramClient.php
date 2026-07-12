<?php

namespace App\Integrations\Telegram;

use Illuminate\Support\Facades\Http;
use App\Integrations\Telegram\Exceptions\TelegramRequestFailed;
use App\Integrations\Telegram\Exceptions\TelegramUnreachable;
use Illuminate\Http\Client\ConnectionException;

class TelegramClient
{
    public function __construct(
        private readonly ?string $botToken,
        private readonly ?string $chatId,
        private readonly int $pollTimeout
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            config('majordom.telegram.bot_token'),
            config('majordom.telegram.chat_id'),
            config('majordom.telegram.poll_timeout', 30)
        );
    }

    public function configured(): bool
    {
        return !empty($this->botToken) && !empty($this->chatId);
    }

    public function sendMessage(string $text, ?array $replyMarkup = null): ?int
    {
        if (!$this->configured()) {
            return null;
        }

        $payload = ['chat_id' => $this->chatId, 'text' => $text];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }

        if (config('majordom.telegram.silent', false)) {
            $payload['disable_notification'] = true;
        }

        return $this->post('sendMessage', $payload)->json('result.message_id');
    }

    public function getUpdates(int $offset = 0): array
    {
        if (!$this->configured()) {
            return [];
        }

        return $this->post('getUpdates', [
            'offset' => $offset,
            'timeout' => $this->pollTimeout,
        ])->json('result', []);
    }

    public function answerCallbackQuery(string $id, string $text = ''): void
    {
        if (!$this->configured()) {
            return;
        }

        $this->post('answerCallbackQuery', [
            'callback_query_id' => $id,
            'text' => $text,
        ]);
    }

    private function post(string $method, array $payload): \Illuminate\Http\Client\Response
    {
        try {
            $response = Http::timeout($this->pollTimeout + 10)
                ->post("https://api.telegram.org/bot{$this->botToken}/{$method}", $payload);
        } catch (ConnectionException $e) {
            throw new TelegramUnreachable('Failed to connect to Telegram API.', 0, $e);
        }

        $json = $response->json();
        if (!$response->successful() || ($json['ok'] ?? false) === false) {
            throw new TelegramRequestFailed(
                $json['description'] ?? 'Unknown Telegram API error',
                $json['error_code'] ?? null
            );
        }

        return $response;
    }
}
