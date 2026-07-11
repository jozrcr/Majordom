<?php

namespace App\Agents\Architect;

use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Enums\MessageRole;
use App\Models\ConsensusMessage;
use App\Models\Project;
use App\Models\Question;
use App\Projects\Memory\MemoryStore;

/**
 * The Architect's consensus orchestration (SPEC §3 phase 1–2, M2 slice).
 *
 * The ask-all-questions mandate is enforced twice: in the system prompt, and
 * mechanically here — consensus_reached is ignored while any question is open
 * or the same turn raised new ones. The model cannot talk its way past the gate.
 */
class ArchitectService
{
    public function __construct(
        private readonly Provider $provider,
        private readonly MemoryStore $memory,
    ) {}

    /**
     * One conversation turn. $userMessage may be null when re-prompting after
     * answers were recorded. Returns the persisted Architect message; when the
     * turn closed consensus, the plan has been written to project memory and
     * 'planWritten' is true.
     *
     * @return array{message: ConsensusMessage, planWritten: bool}
     */
    public function converse(Project $project, ?string $userMessage = null): array
    {
        if ($userMessage !== null && trim($userMessage) !== '') {
            $project->consensusMessages()->create([
                'role' => MessageRole::User,
                'content' => $userMessage,
            ]);
        }

        $response = $this->provider->chat(new ProviderRequest(
            model: (string) config('majordom.architect.model'),
            messages: $this->buildMessages($project),
            maxTokens: (int) config('majordom.architect.max_tokens', 4000),
            temperature: (float) config('majordom.architect.temperature', 0.3),
            jsonMode: true,
        ));

        $envelope = ArchitectEnvelope::fromContent($response->content);

        $message = $project->consensusMessages()->create([
            'role' => MessageRole::Architect,
            'content' => $envelope->reply,
            'meta' => [
                'promptTokens' => $response->promptTokens,
                'completionTokens' => $response->completionTokens,
                'consensusClaimed' => $envelope->consensusReached,
            ],
        ]);

        foreach ($envelope->questions as $q) {
            $project->questions()->create([
                'consensus_message_id' => $message->id,
                'text' => $q['text'],
                'options' => $q['options'],
            ]);
        }

        // The gate: consensus only counts with zero open questions — including
        // ones raised this very turn.
        $planWritten = false;
        if ($envelope->consensusReached && $project->openQuestions()->count() === 0) {
            $this->draftPlan($project);
            $planWritten = true;
        }

        return ['message' => $message, 'planWritten' => $planWritten];
    }

    /**
     * Record the human's answer to an open question. The next converse() call
     * feeds it back to the model as part of the history.
     */
    public function answer(Question $question, string $answer): void
    {
        $question->answerWith($answer);

        $question->project->consensusMessages()->create([
            'role' => MessageRole::User,
            'content' => "**Answer** — {$question->text}\n\n{$answer}",
            'meta' => ['questionId' => $question->id],
        ]);
    }

    /**
     * Phase 2: distill the agreed intent into project memory files.
     */
    private function draftPlan(Project $project): void
    {
        $response = $this->provider->chat(new ProviderRequest(
            model: (string) config('majordom.architect.model'),
            messages: array_merge($this->buildMessages($project), [[
                'role' => 'user',
                'content' => self::PLAN_PROMPT,
            ]]),
            maxTokens: (int) config('majordom.architect.plan_max_tokens', 8000),
            temperature: (float) config('majordom.architect.temperature', 0.3),
            jsonMode: true,
        ));

        $data = json_decode(trim($response->content), true);
        if (! is_array($data)) {
            // Salvage: keep the raw response as a draft rather than losing it.
            $this->memory->write($project, 'plan_draft.md', $response->content);
            $project->consensusMessages()->create([
                'role' => MessageRole::System,
                'content' => 'Consensus reached, but the plan came back malformed — raw draft saved to plan_draft.md. Re-run planning.',
            ]);

            return;
        }

        $taskId = is_string($data['first_task_id'] ?? null) && $data['first_task_id'] !== ''
            ? $data['first_task_id']
            : 'T-001';

        $this->memory->write($project, 'architecture.md', (string) ($data['architecture_md'] ?? ''));
        $this->memory->write($project, 'roadmap.md', (string) ($data['roadmap_md'] ?? ''));
        $this->memory->write($project, "tasks/{$taskId}/task.md", (string) ($data['first_task_md'] ?? ''));

        $project->consensusMessages()->create([
            'role' => MessageRole::System,
            'content' => "Consensus reached — project memory written (architecture.md, roadmap.md, tasks/{$taskId}/task.md).\n\n"
                .(string) ($data['summary'] ?? ''),
            'meta' => ['planWritten' => true, 'firstTaskId' => $taskId],
        ]);
    }

    /** @return array<int, array{role: string, content: string}> */
    private function buildMessages(Project $project): array
    {
        $messages = [[
            'role' => 'system',
            'content' => $this->systemPrompt($project),
        ]];

        foreach ($project->consensusMessages()->orderBy('id')->get() as $m) {
            $messages[] = [
                'role' => $m->role === MessageRole::Architect ? 'assistant' : 'user',
                'content' => $m->role === MessageRole::System
                    ? "[system note] {$m->content}"
                    : $m->content,
            ];
        }

        return $messages;
    }

    private function systemPrompt(Project $project): string
    {
        $open = $project->openQuestions()->pluck('text')->all();
        $openBlock = $open === []
            ? 'There are currently no unanswered questions.'
            : "Unanswered questions you have already raised (do NOT re-raise them):\n- ".implode("\n- ", $open);

        return <<<PROMPT
You are the Architect of the software project "{$project->name}" (repository: {$project->repo_path}).
Your single goal in this conversation is to reach consensus with the human owner on WHAT to build — before any plan is made.

Non-negotiable mandate: surface EVERY open question before proposing anything. Ask, never assume. Questions must be discrete, answerable items — not prose musings.

{$openBlock}

You must respond ONLY with a JSON object of this exact shape (no markdown fences, no text outside the JSON):
{
  "reply": "markdown text shown to the owner — your reasoning, acknowledgements, current understanding",
  "questions": [{"text": "one specific question", "options": ["optional", "answer", "choices"]}],
  "consensus_reached": false
}

Rules:
1. New ambiguities go in "questions" — one entry per question, never buried in "reply".
2. "consensus_reached" may only be true when every question you ever raised has been answered AND this turn raises none. The engine enforces this regardless of what you claim.
3. When consensus_reached is true, "reply" must restate the agreed scope in a few sentences.
4. Keep "reply" concise; the owner reads it in a chat pane.
PROMPT;
    }

    private const PLAN_PROMPT = <<<'PROMPT'
Consensus is reached. Produce the initial project memory now.
Respond ONLY with a JSON object of this exact shape:
{
  "architecture_md": "markdown — the target repo's architecture as you understand it",
  "roadmap_md": "markdown — ordered milestones, each with a one-line goal",
  "first_task_id": "T-001",
  "first_task_md": "markdown — the first task brief: goal, acceptance criteria, files likely involved, test command",
  "summary": "2-3 sentences for the owner: what was agreed and what happens next"
}
PROMPT;
}
