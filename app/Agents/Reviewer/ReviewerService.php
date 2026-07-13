<?php

namespace App\Agents\Reviewer;

use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Core\Usage\UsageLedger;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Support\RoleResolver;

/**
 * The frontier Reviewer (AGENTS.md): judges the Builder's diff against the
 * task's acceptance criteria and the project's coding style. Reads, never
 * writes — a Provider consumer, no Harness anywhere near it.
 */
class ReviewerService
{
    private const MAX_DIFF_CHARS = 30000;

    public function __construct(
        private readonly Provider $provider,
        private readonly MemoryStore $memory,
    ) {}

    public function review(Task $task, string $diff, ?bool $testsPassed): ReviewVerdict
    {
        $project = $task->project;
        $key = $task->task_key;

        $brief = $this->latestBrief($task) ?? '(missing task brief)';
        $style = $this->memory->read($project, 'coding_style.md');
        $handoff = $this->memory->read($project, "tasks/{$key}/handoff.md");

        $testsLine = match ($testsPassed) {
            true => 'Automated tests PASSED.',
            false => 'Automated tests FAILED — approving is almost certainly wrong.',
            null => 'No automated tests were run.',
        };

        $truncated = mb_strlen($diff) > self::MAX_DIFF_CHARS;
        $diffShown = mb_substr($diff, 0, self::MAX_DIFF_CHARS);

        $userContent = "## Task brief\n{$brief}\n\n"
            .($style ? "## Coding style\n{$style}\n\n" : '')
            .($handoff ? "## Builder's handoff\n{$handoff}\n\n" : '')
            ."## Test result\n{$testsLine}\n\n"
            ."## Diff".($truncated ? ' (truncated at 30k chars)' : '')."\n```diff\n{$diffShown}\n```";

        $binding = app(RoleResolver::class)->resolve('reviewer', $project);

        $response = $this->provider->chat(new ProviderRequest(
            model: $binding->model,
            messages: [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userContent],
            ],
            maxTokens: $binding->maxTokens,
            temperature: $binding->temperature,
            jsonMode: true,
        ));

        app(UsageLedger::class)->record(
            $project,
            'reviewer',
            $binding->model,
            $response->promptTokens,
            $response->completionTokens,
            $task->execution, // links the cost to the run — the spend cap reads this
        );

        return ReviewVerdict::fromContent($response->content);
    }

    /** The highest task.v{n}.md revision, falling back to task.md. */
    private function latestBrief(Task $task): ?string
    {
        $key = $task->task_key;

        for ($rev = $task->revision; $rev >= 2; $rev--) {
            $content = $this->memory->read($task->project, "tasks/{$key}/task.v{$rev}.md");
            if ($content !== null) {
                return $content;
            }
        }

        return $this->memory->read($task->project, "tasks/{$key}/task.md");
    }

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the Reviewer: a rigorous senior engineer judging one scoped change.
Judge ONLY what is in front of you: does the diff satisfy the task brief's
acceptance criteria, respect the coding style, and avoid unrelated changes?
You never rewrite code — you deliver a verdict with actionable comments.

Respond ONLY with a JSON object of this exact shape (no markdown fences):
{
  "verdict": "approved" | "changes_requested",
  "comments": [{"file": "path or null", "comment": "one specific, actionable point"}],
  "summary": "2-4 sentences: what the change does and why you ruled as you did",
  "questions": ["only when the OWNER must decide — see rule 4"]
}

Rules:
1. approved requires: acceptance criteria met, no unrelated edits, no obvious
   defects. Style nits alone do not block — mention them as comments.
2. Failing tests are disqualifying unless the brief explicitly says otherwise.
3. Every changes_requested comment must be concrete enough for a builder to
   act on without asking questions.
4. ESCALATE instead of rejecting when the failure is not the builder's to
   fix: ambiguous acceptance criteria, an unstated design choice,
   contradictory requirements, or repeated failures suggesting the brief is
   wrong. Put discrete, answerable owner questions in "questions" (verdict
   stays "changes_requested"). Never use questions for things a competent
   builder should just do.
PROMPT;
}
