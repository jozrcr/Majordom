<?php

namespace App\Integrations\Telegram;

use App\Models\Approval;
use App\Models\CommitSuggestion;
use App\Models\Event;
use App\Models\Question;
use App\Models\TelegramMessage;

/**
 * Mirrors the Needs-You queue to Telegram (SPEC §9: one queue, three
 * windows). Fed by the EventRecorder, fire-and-forget — a Telegram outage
 * must never touch a workflow. Every actionable message stores its
 * reply-mapping row so the inbound daemon can resolve answers.
 */
class TelegramNotifier
{
    public function __construct(private readonly TelegramClient $telegram) {}

    public function handle(Event $event): void
    {
        if (! $this->telegram->configured()) {
            return;
        }

        $project = $event->project;
        $name = $event->name;
        $payload = $event->payload ?? [];

        // Overnight cooking must not buzz the phone: the pile accumulates
        // silently and is read in the morning (SPEC §8).
        $silent = $event->execution?->profile === 'overnight';

        // Architect raised questions → one force-reply message per question.
        if ($name === 'consensus.message' && ($payload['questionsRaised'] ?? 0) > 0 && isset($payload['messageId'])) {
            foreach (Question::open()->where('consensus_message_id', $payload['messageId'])->get() as $question) {
                $text = "[{$project->name}] Question from the Architect:\n{$question->text}";
                if ($question->options) {
                    $text .= "\n\nOptions: ".implode(' · ', $question->options);
                }
                $text .= "\n\nReply to this message to answer.";

                $messageId = $this->telegram->sendMessage($text, ['force_reply' => true], silent: $silent);
                $this->map($project->id, 'question', $question->id, $messageId);
            }

            return;
        }

        // Consensus closed with nothing open → the plan-approval gate.
        if ($name === 'consensus.message' && ($payload['consensusClaimed'] ?? false) && ($payload['questionsRaised'] ?? 0) === 0) {
            $this->telegram->sendMessage(
                "[{$project->name}] Consensus reached — approve the plan? (writes project memory; nothing touches the repo)",
                ['inline_keyboard' => [[
                    ['text' => 'Approve plan', 'callback_data' => "plan:approve:{$project->id}"],
                ]]],
                silent: $silent,
            );

            return;
        }

        // Reviewer escalated: send each execution question as force-reply.
        if (str_ends_with($name, '.clarification') && $event->execution_id) {
            foreach (Question::open()->where('execution_id', $event->execution_id)->get() as $question) {
                $messageId = $this->telegram->sendMessage(
                    "[{$project->name}] The Reviewer needs your call:\n{$question->text}\n\nReply to this message to answer.",
                    ['force_reply' => true],
                    silent: $silent,
                );
                $this->map($project->id, 'question', $question->id, $messageId);
            }

            return;
        }

        // Review gate opened or Human task turn.
        if (str_ends_with($name, '.waiting_human') && $event->execution_id) {
            $approval = Approval::open()->where('execution_id', $event->execution_id)->orderByDesc('id')->first();
            if ($approval) {
                if ($approval->type === \App\Enums\ApprovalType::HumanTask) {
                    $worktree = $approval->payload['worktree'] ?? 'N/A';
                    $this->telegram->sendMessage(
                        "[{$project->name}] Your turn — {$approval->title}\nworktree: {$worktree}",
                        ['inline_keyboard' => [[
                            ['text' => 'Done', 'callback_data' => "approval:grant:{$approval->id}"],
                            ['text' => 'Skip…', 'callback_data' => "approval:reject:{$approval->id}"],
                        ]]],
                        silent: $silent,
                    );
                } else {
                    $tests = match ($approval->payload['testsPassed'] ?? null) {
                        true => 'tests passed',
                        false => 'TESTS FAILING',
                        default => 'no tests',
                    };
                    $summary = $approval->payload['verdict']['summary'] ?? '';

                    $this->telegram->sendMessage(
                        "[{$project->name}] {$approval->title} — {$tests}\n{$summary}",
                        ['inline_keyboard' => [[
                            ['text' => 'Approve', 'callback_data' => "approval:grant:{$approval->id}"],
                            ['text' => 'Reject…', 'callback_data' => "approval:reject:{$approval->id}"],
                        ]]],
                        silent: $silent,
                    );
                }
            }

            return;
        }

        // Commit suggestion ready.
        if ($name === 'commit_suggestion.completed' && $event->execution_id) {
            $suggestion = CommitSuggestion::where('execution_id', $event->execution_id)
                ->where('status', 'suggested')->orderByDesc('id')->first();
            if ($suggestion) {
                $firstLine = strtok($suggestion->message, "\n");
                $this->telegram->sendMessage(
                    "[{$project->name}] Commit ready on {$suggestion->branch}:\n{$firstLine}",
                    ['inline_keyboard' => [[
                        ['text' => 'Commit', 'callback_data' => "commit:apply:{$suggestion->id}"],
                        ['text' => 'Reject…', 'callback_data' => "commit:reject:{$suggestion->id}"],
                    ]]],
                    silent: $silent,
                );
            }

            return;
        }

        // run.escalated → Telegram ping.
        if ($name === 'run.escalated') {
            $this->telegram->sendMessage("[{$project->name}] Run escalated ({$payload['class']}): {$payload['reason']}", silent: $silent);
            return;
        }

        // run.parked → inbox/event only, no Telegram buzz.
        if ($name === 'run.parked') {
            return;
        }

        // Anything parked → plain info line.
        if (str_ends_with($name, '.failed') && isset($payload['reason'])) {
            $this->telegram->sendMessage("[{$project->name}] parked — {$payload['reason']}", silent: $silent);
        }
    }

    private function map(int $projectId, string $kind, ?int $subjectId, ?int $messageId): void
    {
        if ($messageId === null) {
            return;
        }

        TelegramMessage::create([
            'project_id' => $projectId,
            'kind' => $kind,
            'subject_id' => $subjectId,
            'message_id' => $messageId,
        ]);
    }
}
