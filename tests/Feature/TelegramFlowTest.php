<?php

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Enums\ExecutionStatus;
use App\Enums\NodeStatus;
use App\Enums\QuestionStatus;
use App\Integrations\Telegram\TelegramClient;
use App\Integrations\Telegram\TelegramNotifier;
use App\Integrations\Telegram\UpdateHandler;
use App\Models\Approval;
use App\Models\ConsensusMessage;
use App\Models\Event;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Question;
use App\Models\TelegramMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    // Specific patterns only — a catch-all here would shadow per-test fakes
    // (first-registered wins across stacked Http::fake calls).
    Http::fake([
        'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 777]], 200),
        'api.telegram.org/*/answerCallbackQuery' => Http::response(['ok' => true, 'result' => true], 200),
    ]);
    $client = new TelegramClient('test-token', '42', 30);
    app()->instance(TelegramClient::class, $client);
    $this->handler = new UpdateHandler($client);
    $this->notifier = new TelegramNotifier($client);
    $this->project = Project::factory()->create(['name' => 'Proj']);
});

// ---------- outbound (notifier) ----------

it('sends one force-reply message per raised question with a mapping row', function () {
    $msg = ConsensusMessage::create(['project_id' => $this->project->id, 'role' => 'architect', 'content' => 'Qs']);
    $q = Question::factory()->create(['project_id' => $this->project->id, 'consensus_message_id' => $msg->id, 'text' => 'Tabs or spaces?']);

    $event = Event::create([
        'project_id' => $this->project->id, 'name' => 'consensus.message', 'actor' => 'architect',
        'payload' => ['questionsRaised' => 1, 'consensusClaimed' => false, 'messageId' => $msg->id],
    ]);
    $this->notifier->handle($event);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
        && str_contains($r['text'], 'Tabs or spaces?')
        && ($r['reply_markup'] ?? null) !== null);

    $mapping = TelegramMessage::where('kind', 'question')->first();
    expect($mapping->subject_id)->toBe($q->id)->and($mapping->message_id)->toBe(777);
});

it('sends the review gate with approve/reject buttons', function () {
    $execution = Execution::factory()->create(['project_id' => $this->project->id]);
    Approval::create([
        'project_id' => $this->project->id, 'execution_id' => $execution->id,
        'type' => ApprovalType::Review, 'status' => ApprovalStatus::Open,
        'title' => 'Review requested — T-001',
        'payload' => ['testsPassed' => true, 'verdict' => ['summary' => 'Fine.']],
    ]);
    $event = Event::create([
        'project_id' => $this->project->id, 'execution_id' => $execution->id,
        'name' => 'review.waiting_human', 'actor' => 'reviewer', 'payload' => [],
    ]);

    $this->notifier->handle($event);

    Http::assertSent(function ($r) {
        if (! str_contains($r->url(), 'sendMessage')) return false;
        $kb = $r['reply_markup']['inline_keyboard'][0] ?? [];

        return str_contains($r['text'], 'T-001') && count($kb) === 2
            && str_starts_with($kb[0]['callback_data'], 'approval:grant:');
    });
});

it('stays silent when unconfigured', function () {
    $silent = new TelegramNotifier(new TelegramClient(null, null, 30));
    $event = Event::create(['project_id' => $this->project->id, 'name' => 'review.waiting_human', 'actor' => 'reviewer', 'payload' => []]);

    $silent->handle($event);

    Http::assertNothingSent();
});

// ---------- inbound (handler) ----------

it('answers a question from a telegram reply and re-prompts when last', function () {
    $q = Question::factory()->create(['project_id' => $this->project->id, 'text' => 'Choose?']);
    TelegramMessage::create(['project_id' => $this->project->id, 'kind' => 'question', 'subject_id' => $q->id, 'message_id' => 555]);

    $this->handler->handle(['update_id' => 1, 'message' => [
        'text' => 'Spaces, obviously',
        'reply_to_message' => ['message_id' => 555],
    ]]);

    expect($q->fresh()->status)->toBe(QuestionStatus::Answered)
        ->and($q->fresh()->answer)->toBe('Spaces, obviously');
    Queue::assertPushed(\App\Jobs\RunArchitectTurn::class);
});

it('grants an approval from a button tap and the chain resumes', function () {
    $execution = Execution::factory()->create(['project_id' => $this->project->id, 'status' => ExecutionStatus::NeedsYou]);
    $node = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'review', 'status' => NodeStatus::WaitingHuman]);
    $approval = Approval::create([
        'project_id' => $this->project->id, 'execution_id' => $execution->id,
        'type' => ApprovalType::Review, 'status' => ApprovalStatus::Open,
        'title' => 'Review requested — T-001',
        'payload' => ['node_id' => $node->id],
    ]);

    $this->handler->handle(['update_id' => 2, 'callback_query' => [
        'id' => 'cb1', 'data' => "approval:grant:{$approval->id}",
    ]]);

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Granted)
        ->and($execution->fresh()->status)->toBe(ExecutionStatus::Completed);
    Http::assertSent(fn ($r) => str_contains($r->url(), 'answerCallbackQuery'));
});

it('reject flow: button asks for a reason, the reply parks with the comment', function () {
    $execution = Execution::factory()->create(['project_id' => $this->project->id, 'status' => ExecutionStatus::NeedsYou]);
    $node = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'review', 'status' => NodeStatus::WaitingHuman]);
    $approval = Approval::create([
        'project_id' => $this->project->id, 'execution_id' => $execution->id,
        'type' => ApprovalType::Review, 'status' => ApprovalStatus::Open,
        'title' => 'Review requested — T-001',
        'payload' => ['node_id' => $node->id],
    ]);

    $this->handler->handle(['update_id' => 3, 'callback_query' => ['id' => 'cb2', 'data' => "approval:reject:{$approval->id}"]]);

    $mapping = TelegramMessage::where('kind', 'reject_reason')->first();
    expect($mapping)->not->toBeNull()->and($mapping->subject_id)->toBe($approval->id);

    $this->handler->handle(['update_id' => 4, 'message' => [
        'text' => 'Wrong approach', 'reply_to_message' => ['message_id' => $mapping->message_id],
    ]]);

    expect($approval->fresh()->status)->toBe(ApprovalStatus::Rejected)
        ->and($execution->fresh()->status)->toBe(ExecutionStatus::Parked)
        ->and($execution->fresh()->meta['parked_reason'])->toContain('Wrong approach');
});

it('answers /inbox with a summary', function () {
    Question::factory()->create(['project_id' => $this->project->id, 'text' => 'Open one?']);

    $this->handler->handle(['update_id' => 5, 'message' => ['text' => '/inbox']]);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage')
        && str_contains($r['text'], '1 item(s) need you'));
});

// ---------- the daemon ----------

it('poll --once processes updates and advances the offset', function () {
    Http::fake([
        'api.telegram.org/bottest-token/getUpdates*' => Http::response(['ok' => true, 'result' => [
            ['update_id' => 90, 'message' => ['text' => '/inbox']],
        ]], 200),
    ]);
    config(['majordom.telegram.bot_token' => 'test-token', 'majordom.telegram.chat_id' => '42']);
    app()->forgetInstance(TelegramClient::class);
    app()->instance(TelegramClient::class, new TelegramClient('test-token', '42', 30));

    $this->artisan('majordom:telegram-poll', ['--once' => true])->assertSuccessful();

    expect((int) \Illuminate\Support\Facades\Cache::get('telegram:update_offset'))->toBe(91);
});
