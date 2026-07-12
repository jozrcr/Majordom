<?php

namespace App\Console\Commands;

use App\Integrations\Telegram\Exceptions\TelegramUnreachable;
use App\Integrations\Telegram\TelegramClient;
use App\Integrations\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * The two-way inbound channel (HANDOFF M5): a long-polling getUpdates loop —
 * deliberately NOT a public webhook; nothing is exposed to the internet.
 */
class TelegramPoll extends Command
{
    protected $signature = 'majordom:telegram-poll {--once : one getUpdates pass, then exit}';

    protected $description = 'Long-poll Telegram for replies and button taps, mapping them to workflow actions';

    public function handle(TelegramClient $telegram, UpdateHandler $handler): int
    {
        if (! $telegram->configured()) {
            $this->error('TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID are not configured.');

            return self::FAILURE;
        }

        $this->info('Polling Telegram (long poll)… Ctrl-C to stop.');

        do {
            try {
                $offset = (int) Cache::get('telegram:update_offset', 0);

                foreach ($telegram->getUpdates($offset) as $update) {
                    try {
                        $handler->handle($update);
                    } catch (\Throwable $e) {
                        report($e);
                        $this->warn('update failed: '.$e->getMessage());
                    } finally {
                        Cache::put('telegram:update_offset', ($update['update_id'] ?? $offset) + 1);
                    }
                }
            } catch (TelegramUnreachable) {
                $this->warn('Telegram unreachable — retrying in 5s.');
                sleep(5);
            } catch (\Throwable $e) {
                report($e);
                $this->warn('poll error: '.$e->getMessage());
                sleep(5);
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }
}
