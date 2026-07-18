<?php

namespace App\Agents\Reviewer;

use App\Agents\Providers\ProviderRegistry;
use App\Agents\Providers\ProviderRequest;
use App\Core\Usage\UsageLedger;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\RepoIndex;
use App\Support\RoleBinding;
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
        private readonly ProviderRegistry $providers,
        private readonly MemoryStore $memory,
        private readonly RepoIndex $repoIndex,
    ) {}

    public function review(Task $task, string $diff, ?bool $testsPassed, ?RoleBinding $binding = null): ReviewVerdict
    {
        $project = $task->project;
        $key = $task->task_key;

        $brief = $this->latestBrief($task) ?? '(missing task brief)';
        $style = $this->memory->read($project, 'coding_style.md');
        $handoff = $this->memory->read($project, "tasks/{$key}/handoff.md");

        $testsLine = match ($testsPassed) {
            true => 'Automated tests PASSED.',
            false => 'Automated tests FAILED — approving is almost certainly wrong.',
            null => ($project->test_command && trim($project->test_command) !== '')
                ? 'No automated tests were run.'
                : 'This project has NO automated test runner — do not request test runs or reject for missing test output; judge the diff on its own.',
        };

        $truncated = mb_strlen($diff) > self::MAX_DIFF_CHARS;
        $diffShown = mb_substr($diff, 0, self::MAX_DIFF_CHARS);

        // Grounding (e2e #2): the reviewer sees the real tracked file list so
        // "missing file" objections reference reality, not guesses.
        $tree = $this->repoIndex->fileList($project->repo_path);
        $treeBlock = $tree ? "## Repository files (tracked)\n{$tree}\n\n" : '';

        $userContent = "## Task brief\n{$brief}\n\n"
            .($style ? "## Coding style\n{$style}\n\n" : '')
            .($handoff ? "## Builder's handoff\n{$handoff}\n\n" : '')
            .$treeBlock
            ."## Test result\n{$testsLine}\n\n"
            ."## Diff".($truncated ? ' (truncated at 30k chars)' : '')."\n```diff\n{$diffShown}\n```";

        if ($binding === null) {
            $binding = app(RoleResolver::class)->resolve('reviewer', $project);
        }

        $extraSystem = $binding->meta['system_prompt_extra'] ?? null;
        $systemPrompt = self::SYSTEM_PROMPT;
        if ($extraSystem !== null && trim($extraSystem) !== '') {
            $systemPrompt .= "\n\n" . trim($extraSystem);
        }

        $response = $this->providers->forBinding($binding)->chat(new ProviderRequest(
            model: $binding->model,
            messages: [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ],
            maxTokens: $binding->maxTokens,
            temperature: $binding->temperature,
            jsonMode: true,
            topP: isset($binding->meta['top_p']) ? (float) $binding->meta['top_p'] : null,
            frequencyPenalty: isset($binding->meta['frequency_penalty']) ? (float) $binding->meta['frequency_penalty'] : null,
            presencePenalty: isset($binding->meta['presence_penalty']) ? (float) $binding->meta['presence_penalty'] : null,
            stop: isset($binding->meta['stop']) ? $binding->meta['stop'] : null,
            timeout: isset($binding->meta['timeout']) ? (int) $binding->meta['timeout'] : null,
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

    /**
     * The ACCEPTANCE CRITERIA the reviewer judges against — the original task.md,
     * plus owner clarifications (which are binding), but NEVER the reviewer's own
     * prior review comments.
     *
     * The revision brief (task.v{n}.md) folds the last round's comments back in;
     * feeding those to the reviewer made it treat its own escalating remarks as
     * new requirements and reject forever ("disqualifying per the review
     * comments"). The reviewer must judge the task as specified, not re-litigate
     * itself — so a review-comment revision reverts to the original criteria,
     * while an owner-clarification revision (real criteria) is honored.
     */
    private function latestBrief(Task $task): ?string
    {
        $key = $task->task_key;
        $original = $this->memory->read($task->project, "tasks/{$key}/task.md");

        for ($rev = $task->revision; $rev >= 2; $rev--) {
            $content = $this->memory->read($task->project, "tasks/{$key}/task.v{$rev}.md");
            if ($content !== null) {
                // Owner clarifications ARE binding criteria — honor them. A
                // review-comments revision is not: judge the original criteria.
                return str_contains($content, '## Owner clarifications') ? $content : $original;
            }
        }

        return $original;
    }

    private const SYSTEM_PROMPT = <<<'PROMPT'
You are the Reviewer: a pragmatic senior engineer judging one scoped change.
The ACCEPTANCE CRITERIA in the task brief are the ONLY bar. Your job: does the
diff satisfy each stated criterion, without breaking tests or making unrelated
changes? You never rewrite code — you deliver a verdict with actionable comments.

Respond ONLY with a JSON object of this exact shape (no markdown fences):
{
  "verdict": "approved" | "changes_requested",
  "comments": [{"file": "path or null", "comment": "one specific, actionable point"}],
  "summary": "2-4 sentences: what the change does and why you ruled as you did",
  "questions": ["only when the OWNER must decide — see rule 5"]
}

Rules:
1. If every acceptance criterion is satisfied and tests pass, you MUST approve —
   even if you can imagine stricter standards, extra edge cases, or nicer code.
2. Do NOT invent requirements. Never reject for something the criteria do not
   ask for (a parameter the brief didn't require, a hypothetical caller, a
   defensive check, broader backward-compat than specified). Judge the task as
   written, not the task you would have written.
3. Reject ONLY for: an unmet acceptance criterion, failing tests (disqualifying
   unless the brief says otherwise), an unambiguous bug (crash/incorrect output),
   or unrelated/out-of-scope edits. Style nits alone never block — note them.
4. Every changes_requested comment must be concrete and tied to a specific
   acceptance criterion it violates, so a builder can act without asking.
5. ESCALATE (questions, verdict stays changes_requested) instead of rejecting
   when the failure is the OWNER's to resolve: ambiguous/contradictory criteria,
   an unstated design choice, or you have already requested changes ~twice and
   the diff now reasonably satisfies the criteria — a further rejection likely
   means the brief, not the builder, is the problem. Never use questions for
   things a competent builder should just do.
PROMPT;
}
