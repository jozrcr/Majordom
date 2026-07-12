<?php

use App\Integrations\Telegram\TelegramClient;
use App\Integrations\Telegram\Exceptions\TelegramRequestFailed;
use App\Integrations\Telegram\Exceptions\TelegramUnreachable;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

it('sends message and returns message_id', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 123]], 200),
    ]);

    $client = new TelegramClient('test-token', '42', 30);
    $id = $client->sendMessage('Hello');

    expect($id)->toBe(123);
    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.telegram.org/bottest-token/sendMessage'
            && $request['chat_id'] === '42'
            && $request['text'] === 'Hello';
    });
});

it('sends message with inline_keyboard reply_markup', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 456]], 200),
    ]);

    $markup = ['inline_keyboard' => [['text' => 'Click', 'callback_data' => 'btn']]];
    $client = new TelegramClient('test-token', '42', 30);
    $id = $client->sendMessage('Choose', $markup);

    expect($id)->toBe(456);
    Http::assertSent(function ($request) use ($markup) {
        return $request['reply_markup'] === $markup;
    });
});

it('does nothing when unconfigured', function () {
    Http::fake();
    $client = new TelegramClient(null, null, 30);

    expect($client->sendMessage('Hi'))->toBeNull();
    expect($client->getUpdates())->toBe([]);
    $client->answerCallbackQuery('q1');

    Http::assertNothingSent();
});

it('gets updates with offset and timeout', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true, 'result' => [['update_id' => 1]]], 200),
    ]);

    $client = new TelegramClient('test-token', '42', 30);
    $updates = $client->getUpdates(10);

    expect($updates)->toBe([['update_id' => 1]]);
    Http::assertSent(function ($request) {
        return $request['offset'] === 10 && $request['timeout'] === 30;
    });
});

it('throws TelegramRequestFailed on ok=false', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found', 'error_code' => 400], 400),
    ]);

    $client = new TelegramClient('test-token', '42', 30);
    
    $client->sendMessage('Hi');
})->throws(TelegramRequestFailed::class, 'Bad Request: chat not found');

it('throws TelegramUnreachable on connection failure', function () {
    Http::fake(fn () => throw new ConnectionException('down'));

    $client = new TelegramClient('test-token', '42', 30);
    
    $client->sendMessage('Hi');
})->throws(TelegramUnreachable::class);

it('answers callback query', function () {
    Http::fake([
        'api.telegram.org/*' => Http::response(['ok' => true], 200),
    ]);

    $client = new TelegramClient('test-token', '42', 30);
    $client->answerCallbackQuery('q1', 'Done');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.telegram.org/bottest-token/answerCallbackQuery'
            && $request['callback_query_id'] === 'q1'
            && $request['text'] === 'Done';
    });
});

it('sends silently when majordom.telegram.silent is on', function () {
    config(['majordom.telegram.silent' => true]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

    (new TelegramClient('test-token', '42', 30))->sendMessage('Hi');

    Http::assertSent(fn ($r) => ($r['disable_notification'] ?? false) === true);
});
