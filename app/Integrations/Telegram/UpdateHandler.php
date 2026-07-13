<?php

namespace App\Integrations\Telegram;

use App\Agents\Architect\ArchitectService;
use App\Core\Workflow\WorkflowEngine;
use App\Enums\ProjectStatus;
use App\Enums\QuestionStatus;
use App\Jobs\RunArchitectTurn;
use App\Jobs\RunPlanDraft;
use App\Livewire\Inbox;
use App\Models\Approval;
use App\Models\CommitSuggestion;
use App\Models\Project;
use App\Models\Question;
use App\Models\TelegramMessage;
use App\Projects\Repositories\CommitService;
use Illuminate\Support\Facades\Cache;

/**
 * Maps inbound Telegram updates to the SAME actions the web UI performs —
 * one queue, three windows (SPEC §9). Nothing here has authority the
 * workspace buttons don't have; the human's tap is the gate.
 */
class UpdateHandler
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if ($message === null) {
            return;
        }

        if (isset($message['reply_to_message'])) {
            $this->handleReply($message);

            return;
        }

        if (trim($message['text'] ?? '') === '/inbox') {
            $this->sendInboxSummary();
        }
    }

    private function handleCallback(array $callback): void
    {
        [$domain, $action, $id] = array_pad(explode(':', $callback['data'] ?? '', 3), 3, null);
        $callbackId = $callback['id'] ?? '';

        match (true) {
            $domain === 'approval' && $action === 'grant' => $this->grantApproval((int) $id, $callbackId),
            $domain === 'approval' && $action === 'reject' => $this->askRejectReason((int) $id, $callbackId),
            $domain === 'plan' && $action === 'approve' => $this->approvePlan((int) $id, $callbackId),
            $domain === 'commit' && $action === 'apply' => $this->applyCommit((int) $id, $callbackId),
            $domain === 'commit' && $action === 'reject' => $this->askCommitRejectReason((int) $id, $callbackId),
            default => $this->telegram->answerCallbackQuery($callbackId, 'Unknown action.'),
        };
    }

    private function grantApproval(int $id, string $callbackId): void
    {
        $approval = Approval::open()->find($id);
        if (! $approval) {
            $this->telegram->answerCallbackQuery($callbackId, 'Already resolved.');

            return;
        }

        app(WorkflowEngine::class)->resolveApproval($approval, granted: true, comment: 'Approved via Telegram.');
        $this->telegram->answerCallbackQuery($callbackId, 'Approved — the chain continues.');
    }

    private function askRejectReason(int $id, string $callbackId): void
    {
        $approval = Approval::open()->find($id);
        if (! $approval) {
            $this->telegram->answerCallbackQuery($callbackId, 'Already resolved.');

            return;
        }

        $messageId = $this->telegram->sendMessage(
            "Reason for rejecting \"{$approval->title}\"? Reply to this message.",
            ['force_reply' => true],
        );
        if ($messageId !== null) {
            TelegramMessage::create([
                'project_id' => $approval->project_id,
                'kind' => 'reject_reason',
                'subject_id' => $approval->id,
                'message_id' => $messageId,
            ]);
        }
        $this->telegram->answerCallbackQuery($callbackId, 'Reply with the reason.');
    }

    private function approvePlan(int $projectId, string $callbackId): void
    {
        $project = Project::find($projectId);
        if (! $project) {
            $this->telegram->answerCallbackQuery($callbackId, 'Project not found.');

            return;
        }

        Cache::put("architect-turn:{$project->id}", 'planning', now()->addMinutes(15));
        $project->update(['status' => ProjectStatus::Working, 'last_activity_at' => now()]);
        RunPlanDraft::dispatch($project->id)->onConnection('harness')->onQueue('harness');

        $this->telegram->answerCallbackQuery($callbackId, 'Plan drafting started.');
    }

    private function applyCommit(int $id, string $callbackId): void
    {
        $suggestion = CommitSuggestion::find($id);
        if (! $suggestion || $suggestion->status !== 'suggested') {
            $this->telegram->answerCallbackQuery($callbackId, 'Already resolved.');

            return;
        }

        try {
            app(CommitService::class)->apply($suggestion);
            $this->telegram->answerCallbackQuery($callbackId, 'Committed.');
        } catch (\RuntimeException $e) {
            $this->telegram->answerCallbackQuery($callbackId, mb_substr($e->getMessage(), 0, 180));
        }
    }

    private function askCommitRejectReason(int $id, string $callbackId): void
    {
        $suggestion = CommitSuggestion::find($id);
        if (! $suggestion || $suggestion->status !== 'suggested') {
            $this->telegram->answerCallbackQuery($callbackId, 'Already resolved.');

            return;
        }

        $messageId = $this->telegram->sendMessage(
            'Reason for rejecting this commit? Reply to this message.',
            ['force_reply' => true],
        );
        if ($messageId !== null) {
            TelegramMessage::create([
                'project_id' => $suggestion->project_id,
                'kind' => 'commit_reject_reason',
                'subject_id' => $suggestion->id,
                'message_id' => $messageId,
            ]);
        }
        $this->telegram->answerCallbackQuery($callbackId, 'Reply with the reason.');
    }

    private function handleReply(array $message): void
    {
        $repliedId = $message['reply_to_message']['message_id'] ?? null;
        $text = trim($message['text'] ?? '');
        if ($repliedId === null || $text === '') {
            return;
        }

        $mapping = TelegramMessage::where('message_id', $repliedId)->first();
        if (! $mapping) {
            return;
        }

        match ($mapping->kind) {
            'question' => $this->answerQuestion($mapping, $text),
            'reject_reason' => $this->rejectApproval($mapping, $text),
            'commit_reject_reason' => $this->rejectCommit($mapping, $text),
            default => null,
        };
    }

    private function answerQuestion(TelegramMessage $mapping, string $text): void
    {
        $question = Question::find($mapping->subject_id);
        if (! $question || $question->status !== QuestionStatus::Open) {
            $this->telegram->sendMessage('That question was already answered.');

            return;
        }

        app(ArchitectService::class)->answer($question, $text);

        if ($question->execution_id) {
            $execution = $question->execution;
            if ($execution && $execution->questions()->open()->count() === 0) {
                app(\App\Core\Workflow\WorkflowEngine::class)->resumeAfterClarification($execution);
                $this->telegram->sendMessage('All clarifications in — the build is re-running with your answers.');
            } else {
                $remaining = $execution?->questions()->open()->count() ?? 0;
                $this->telegram->sendMessage("Answer recorded — {$remaining} clarification(s) remaining.");
            }

            return;
        }

        $project = $question->project;
        if ($project->openQuestions()->count() === 0) {
            Cache::put("architect-turn:{$project->id}", 'thinking', now()->addMinutes(15));
            RunArchitectTurn::dispatch($project->id, null)->onConnection('harness')->onQueue('harness');
            $this->telegram->sendMessage("Answer recorded — all questions done, the Architect is thinking.");
        } else {
            $remaining = $project->openQuestions()->count();
            $this->telegram->sendMessage("Answer recorded — {$remaining} question(s) remaining.");
        }
    }

    private function rejectApproval(TelegramMessage $mapping, string $text): void
    {
        $approval = Approval::open()->find($mapping->subject_id);
        if (! $approval) {
            $this->telegram->sendMessage('That review was already resolved.');

            return;
        }

        app(WorkflowEngine::class)->resolveApproval($approval, granted: false, comment: $text);
        $this->telegram->sendMessage('Rejected — the execution is parked with your reason.');
    }

    private function rejectCommit(TelegramMessage $mapping, string $text): void
    {
        $suggestion = CommitSuggestion::find($mapping->subject_id);
        if (! $suggestion || $suggestion->status !== 'suggested') {
            $this->telegram->sendMessage('That commit was already resolved.');

            return;
        }

        app(CommitService::class)->reject($suggestion, $text);
        $this->telegram->sendMessage('Commit rejected and discarded; the branch survives.');
    }

    private function sendInboxSummary(): void
    {
        $count = Inbox::openCount();
        if ($count === 0) {
            $this->telegram->sendMessage('All quiet. Nothing needs you.');

            return;
        }

        $lines = ["{$count} item(s) need you:"];
        foreach (Question::open()->with('project')->limit(5)->get() as $q) {
            $lines[] = "· [{$q->project->name}] question: ".mb_substr($q->text, 0, 80);
        }
        foreach (Approval::open()->with('project')->limit(5)->get() as $a) {
            $lines[] = "· [{$a->project->name}] {$a->title}";
        }
        foreach (CommitSuggestion::where('status', 'suggested')->with('project')->limit(5)->get() as $c) {
            $lines[] = "· [{$c->project->name}] commit ready: ".strtok($c->message, "\n");
        }

        $this->telegram->sendMessage(implode("\n", $lines));
    }
}
