<?php

namespace App\Agents\Reviewer;

use App\Agents\Providers\ProviderRegistry;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Providers\ToolCall;
use App\Agents\Providers\ToolDefinition;
use App\Agents\Providers\ToolMessages;
use App\Core\Usage\UsageLedger;
use App\Models\Milestone;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\MilestoneDiff;
use App\Projects\Repositories\RepoIndex;
use App\Support\RoleBinding;
use App\Support\RoleResolver;

/**
 * The milestone review (M15 canonical slice): the Architect judges a milestone's
 * CUMULATIVE work (base_commit..HEAD on majordom/<key>) against the milestone goal
 * and its tasks' acceptance criteria — the right altitude, where "is this actually
 * good?" is answerable. Per-task review is gone; this replaces it.
 *
 * Driven by the tool contract: read_diff / read_file ground the judgment in the
 * real cumulative diff (so a fragment can never be mis-judged — the T-73 class of
 * bug is impossible), then exactly one of approve_milestone / request_changes /
 * ask_owner terminates. There is no "invent a requirement" tool and the surface is
 * bounded, so the T-76 non-convergence spiral is structurally bounded too.
 *
 * Defaults to the Architect's own binding via the `reviewer` role (one mind);
 * owners may bind a distinct reviewer model for diversity. Reads, never writes.
 */
class MilestoneReviewService
{
    private const MAX_ROUNDS = 6;
    private const MAX_DIFF_CHARS = 30000;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly MemoryStore $memory,
        private readonly RepoIndex $repoIndex,
        private readonly MilestoneDiff $diffs,
    ) {}

    public function review(Milestone $milestone): MilestoneReviewOutcome
    {
        $project = $milestone->project;
        $worktree = $this->diffs->worktree($milestone);
        $diff = $this->diffs->cumulative($milestone);

        // Nothing to review (no worktree, or a milestone whose tasks were all
        // no-ops) — approve and let the boundary proceed.
        if (trim($diff) === '') {
            return new MilestoneReviewOutcome('approved', 'No changes to review for this milestone.');
        }

        $binding = app(RoleResolver::class)->resolve('reviewer', $project);
        $messages = [
            ['role' => 'system', 'content' => $this->systemPrompt($binding)],
            ['role' => 'user', 'content' => $this->reviewContext($milestone)],
        ];
        $tools = $this->reviewTools();

        $rounds = 0;
        while (true) {
            $response = $this->reviewChat($milestone, $binding, $messages, $tools);

            if (! $response->hasToolCalls()) {
                // No tool call — treat the text as a soft approval note rather than
                // stalling (the loop must resolve). Prefer a real verdict next time.
                return new MilestoneReviewOutcome('approved', $response->content !== '' ? $response->content : 'Reviewed — no blocking issues.');
            }

            $messages[] = ToolMessages::assistantToolCalls($response);

            foreach ($response->toolCalls as $call) {
                if ($call->name === 'approve_milestone') {
                    $howToTest = is_string($call->arguments['how_to_test'] ?? null) && trim((string) $call->arguments['how_to_test']) !== ''
                        ? trim((string) $call->arguments['how_to_test'])
                        : null;

                    return new MilestoneReviewOutcome('approved', (string) ($call->arguments['summary'] ?? 'Milestone approved.'), howToTest: $howToTest);
                }
                if ($call->name === 'request_changes') {
                    return new MilestoneReviewOutcome('changes', (string) ($call->arguments['summary'] ?? 'Changes requested.'), $this->parseItems($call));
                }
                if ($call->name === 'ask_owner') {
                    return new MilestoneReviewOutcome('escalate', (string) ($call->arguments['summary'] ?? ''), [], $this->parseQuestions($call));
                }
                if ($call->name === 'read_diff') {
                    $messages[] = ToolMessages::toolResult($call->id, $this->renderDiff($diff));

                    continue;
                }
                if ($call->name === 'read_file') {
                    $messages[] = ToolMessages::toolResult($call->id, $this->readFile($worktree, $call));

                    continue;
                }
                $messages[] = ToolMessages::toolResult($call->id, "Unknown tool '{$call->name}'.");
            }

            if (++$rounds >= self::MAX_ROUNDS) {
                // Runaway guard: force a verdict by offering only terminal tools.
                $tools = $this->reviewTools(withReads: false);
            }
            if ($rounds >= self::MAX_ROUNDS + 2) {
                return new MilestoneReviewOutcome('escalate', 'The milestone review did not conclude — please look at it.', [], ['Does the milestone meet its goal, or what still needs to change?']);
            }
        }
    }

    private function reviewChat(Milestone $milestone, RoleBinding $binding, array $messages, array $tools): ProviderResponse
    {
        $response = $this->providers->forBinding($binding)->chat(new ProviderRequest(
            model: $binding->model,
            messages: $messages,
            maxTokens: $binding->maxTokens,
            temperature: $binding->temperature,
            tools: $tools,
            toolChoice: 'auto',
            topP: isset($binding->meta['top_p']) ? (float) $binding->meta['top_p'] : null,
            timeout: isset($binding->meta['timeout']) ? (int) $binding->meta['timeout'] : null,
        ));

        // Spend links to no single execution here (the review spans the milestone);
        // record it against the reviewer role so caps still see it.
        app(UsageLedger::class)->record($milestone->project, 'reviewer', $binding->model, $response->promptTokens, $response->completionTokens);

        return $response;
    }

    /** @return ToolDefinition[] */
    private function reviewTools(bool $withReads = true): array
    {
        $approve = new ToolDefinition(
            name: 'approve_milestone',
            description: 'Approve the milestone when its cumulative work satisfies its goal and every task\'s acceptance criteria. Ends the review.',
            parameters: ['type' => 'object', 'properties' => [
                'summary' => ['type' => 'string', 'description' => '2-4 sentences: what the milestone delivers and why it passes.'],
                'how_to_test' => ['type' => 'string', 'description' => 'Concrete steps the owner can run to verify this milestone works end-to-end (commands to run, what to click, what they should see). This goes on the merge gate so the owner can check it themselves before merging.'],
            ], 'required' => ['summary', 'how_to_test']],
        );
        $changes = new ToolDefinition(
            name: 'request_changes',
            description: 'Request specific, concrete changes when the milestone does NOT meet its goal or a task\'s acceptance criteria. Each item becomes a keyed fix-task. Ends the review.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string', 'description' => '1-2 sentences: the overall gap.'],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'task_key' => ['type' => 'string', 'description' => 'The task this relates to (optional).'],
                                'file' => ['type' => 'string', 'description' => 'The file, if specific (optional).'],
                                'reason' => ['type' => 'string', 'description' => 'The concrete, actionable change, tied to an acceptance criterion it violates.'],
                            ],
                            'required' => ['reason'],
                        ],
                    ],
                ],
                'required' => ['summary', 'items'],
            ],
        );
        $ask = new ToolDefinition(
            name: 'ask_owner',
            description: 'Escalate to the owner ONLY for an ambiguity that is theirs to resolve (contradictory criteria, an unstated design choice). Not for anything a competent builder should just fix.',
            parameters: ['type' => 'object', 'properties' => ['summary' => ['type' => 'string'], 'questions' => ['type' => 'array', 'items' => ['type' => 'string']]], 'required' => ['questions']],
        );

        if (! $withReads) {
            return [$approve, $changes, $ask];
        }

        return [
            new ToolDefinition('read_diff', 'Read the milestone\'s cumulative diff (all of its work since it branched from main). Call this first.', ['type' => 'object', 'properties' => new \stdClass]),
            new ToolDefinition('read_file', 'Read one tracked file from the milestone worktree for context.', ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]),
            $approve,
            $changes,
            $ask,
        ];
    }

    private function renderDiff(string $diff): string
    {
        $truncated = mb_strlen($diff) > self::MAX_DIFF_CHARS;

        return "Cumulative milestone diff".($truncated ? ' (truncated at 30k chars)' : '').":\n```diff\n".mb_substr($diff, 0, self::MAX_DIFF_CHARS)."\n```";
    }

    private function readFile(string $worktree, ToolCall $call): string
    {
        $path = is_string($call->arguments['path'] ?? null) ? trim((string) $call->arguments['path']) : '';
        if ($path === '') {
            return 'read_file requires a "path".';
        }

        return $this->repoIndex->readFile($worktree, $path) ?? '(unreadable or not a tracked file)';
    }

    /** @return array<int, array{task_key: ?string, file: ?string, reason: string}> */
    private function parseItems(ToolCall $call): array
    {
        $out = [];
        foreach (is_array($call->arguments['items'] ?? null) ? $call->arguments['items'] : [] as $it) {
            if (is_string($it) && trim($it) !== '') {
                $out[] = ['task_key' => null, 'file' => null, 'reason' => trim($it)];
            } elseif (is_array($it) && is_string($it['reason'] ?? null) && trim($it['reason']) !== '') {
                $out[] = [
                    'task_key' => is_string($it['task_key'] ?? null) && trim($it['task_key']) !== '' ? trim($it['task_key']) : null,
                    'file' => is_string($it['file'] ?? null) && trim($it['file']) !== '' ? trim($it['file']) : null,
                    'reason' => trim($it['reason']),
                ];
            }
        }

        return $out;
    }

    /** @return string[] */
    private function parseQuestions(ToolCall $call): array
    {
        $out = [];
        foreach (is_array($call->arguments['questions'] ?? null) ? $call->arguments['questions'] : [] as $q) {
            if (is_string($q) && trim($q) !== '') {
                $out[] = trim($q);
            }
        }

        return $out === [] ? ['This milestone needs your input to proceed.'] : $out;
    }

    private function reviewContext(Milestone $milestone): string
    {
        $project = $milestone->project;
        $goal = trim((string) $milestone->summary) !== '' ? $milestone->summary : '(no explicit goal recorded)';

        $tasks = '';
        foreach ($milestone->tasks as $t) {
            $brief = $this->memory->read($project, "tasks/{$t->task_key}/task.md");
            $tasks .= "### {$t->task_key} — {$t->title}\n".($brief ? trim($brief) : '(no brief)')."\n\n";
        }

        $style = $this->memory->read($project, 'coding_style.md');
        $styleBlock = $style ? "## Project coding style\n{$style}\n\n" : '';

        return <<<CTX
Review milestone {$milestone->milestone_key} — {$milestone->title}.

## Milestone goal
{$goal}

## Tasks in this milestone (their acceptance criteria are the bar)
{$tasks}
{$styleBlock}Call read_diff to see the milestone's cumulative work, then rule. Judge the milestone AS A WHOLE against the goal and the tasks' acceptance criteria — not each fragment in isolation.
CTX;
    }

    private function systemPrompt(RoleBinding $binding): string
    {
        $prompt = <<<'PROMPT'
You are reviewing a completed MILESTONE — a coherent chunk of work — that you did not write. Judge its CUMULATIVE diff against the milestone goal and the tasks' acceptance criteria.

How you work — through tools:
- Call read_diff first to see everything the milestone changed. Use read_file for any file you need in fuller context.
- Then rule EXACTLY ONCE: approve_milestone if the milestone as a whole meets its goal and every task's acceptance criteria; request_changes with specific, actionable items (each becomes a keyed fix-task) if it does not; ask_owner only for an ambiguity that is genuinely the owner's to resolve.

Rules:
1. Judge the milestone as a WHOLE. Do not reject because one task's slice looks small — later or earlier tasks may cover it; that is why you see the cumulative diff.
2. Do NOT invent requirements. If the goal and acceptance criteria are met, you MUST approve — even if you can imagine stricter standards or nicer code.
3. request_changes only for a real gap: an unmet acceptance criterion, an unambiguous bug, or work that contradicts the goal. Every item must be concrete and tied to the criterion it violates, so a builder can act without asking.
4. Prefer approving a sound milestone over a spiral of nitpicks — the owner does the final end-to-end test.
PROMPT;

        $extra = $binding->meta['system_prompt_extra'] ?? null;
        if (is_string($extra) && trim($extra) !== '') {
            $prompt .= "\n\n".trim($extra);
        }

        return $prompt;
    }
}
