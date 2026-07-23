<?php

namespace App\Agents\Architect;

use App\Agents\Providers\ProviderRegistry;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Providers\ToolCall;
use App\Agents\Providers\ToolDefinition;
use App\Agents\Providers\ToolMessages;
use App\Core\Events\EventRecorder;
use App\Core\Usage\UsageLedger;
use App\Enums\MessageRole;
use App\Enums\ProjectStatus;
use App\Models\ConsensusMessage;
use App\Models\Project;
use App\Models\Question;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\RepoIndex;
use App\Support\RoleBinding;
use App\Support\RoleResolver;
use Illuminate\Support\Str;

/**
 * The Architect's consensus orchestration (SPEC §3 phase 1–2), driven by the
 * M15 tool contract. A turn ends in exactly one known state — a read (loop
 * continues), ask_owner (questions), propose_plan (consensus, human-gated), or a
 * plain-text reply (the owner's turn) — so there is no "stalled" dead end to
 * detect. The plan-approval gate still holds: a proposed plan only counts as
 * pending with zero open questions, and nothing is written until the owner approves.
 */
class ArchitectService
{
    /** Max repo-read rounds the Architect may self-drive per turn before it must
     *  ask or conclude (M15 runaway guard — reads can't loop forever). */
    private const MAX_READ_ROUNDS = 4;

    /** Per-file read cap, and total budget across one inspection round (bytes). */
    private const READ_MAX_BYTES = 4000;
    private const GATHER_MAX_TOTAL = 16000;

    /** T-67: greenfield scaffold instruction. */
    private const BOOTSTRAP_PROMPT = <<<'PROMPT'
The plan is approved and the repository is currently EMPTY. Produce the initial
project scaffold the first task will build on — nothing more.

Respond ONLY with a JSON object (no markdown fences, no prose outside the JSON):
{
  "files": [{"path": "relative/path", "contents": "full file contents"}],
  "commit_message": "chore: scaffold project structure"
}

Include only foundation: directory layout, dependency/package manifests, a README,
test runner/config, and an entry point — grounded in the agreed architecture. Do
NOT implement any feature or task logic; leave that to the Builder. Keep files
minimal but runnable. Use repo-relative paths (no leading slash, no "..").
PROMPT;

    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly MemoryStore $memory,
        private readonly RepoIndex $repoIndex,
    ) {}

    /**
     * One consensus turn, driven by tool calls (M15 tool contract). The Architect
     * either calls read tools (read_file / list_repo — the engine fulfills them and
     * the loop continues) or terminates the turn in exactly one known way:
     *   • ask_owner    — surfaces questions; the owner's turn next
     *   • propose_plan — consensus reached; the plan is captured, still human-gated
     *   • plain text   — a conversational reply; the owner's turn next
     * There is no fourth outcome, so the old "stalled with nothing pending" dead
     * end (T-65) cannot occur — the never-stall invariant is structural, not detected.
     *
     * @return array{message: ConsensusMessage, consensusPending: bool}
     */
    public function converse(Project $project, ?string $userMessage = null): array
    {
        if ($userMessage !== null && trim($userMessage) !== '') {
            $project->consensusMessages()->create([
                'role' => MessageRole::User,
                'content' => $userMessage,
            ]);
        }

        $binding = app(RoleResolver::class)->resolve('architect', $project);
        $extraSystem = $binding->meta['system_prompt_extra'] ?? null;
        $canRead = $project->capability()->canRead();

        // The tool loop runs over an in-memory copy of the conversation; only the
        // final human-visible outcome is persisted. Reads stay ephemeral — recorded
        // as events for the trace, never replayed into every future turn (which is
        // what used to bloat the context in the old inspection design).
        $messages = $this->buildMessages($project, $extraSystem);

        $readRounds = 0;
        $outcome = null;
        $lastResponse = null;

        while ($outcome === null) {
            // On the last permitted round, withdraw the read tools so the model
            // must ask or conclude — reads cannot loop forever.
            $offerReads = $canRead && $readRounds < self::MAX_READ_ROUNDS;
            $response = $this->architectChat($project, $binding, $messages, $this->consensusTools($offerReads));
            $lastResponse = $response;

            if (! $response->hasToolCalls()) {
                $outcome = ['type' => 'reply', 'reply' => $response->content];
                break;
            }

            // Replay the assistant's tool-call turn so the next request is valid.
            $messages[] = ToolMessages::assistantToolCalls($response);

            $didRead = false;
            foreach ($response->toolCalls as $call) {
                // A terminating tool wins immediately, even if the same turn also
                // requested reads — a real question/plan always reaches the owner.
                if ($call->name === 'ask_owner') {
                    $outcome = ['type' => 'questions', 'reply' => $response->content, 'questions' => $this->parseAskOwner($call)];
                    break;
                }
                if ($call->name === 'propose_plan') {
                    $outcome = ['type' => 'plan', 'reply' => $response->content, 'plan' => $this->normalizePlan($call->arguments)];
                    break;
                }
                if ($call->name === 'read_file' || $call->name === 'list_repo') {
                    $messages[] = ToolMessages::toolResult($call->id, $this->runReadTool($project, $call));
                    $didRead = true;

                    continue;
                }
                $messages[] = ToolMessages::toolResult($call->id, "Unknown tool '{$call->name}'. Use read_file, list_repo, ask_owner, or propose_plan.");
            }

            if ($outcome === null) {
                // Advance the round counter whether the model read or emitted only
                // unknown tools; the cap guards against any non-terminating loop.
                $readRounds++;
                if (! $didRead && $readRounds >= self::MAX_READ_ROUNDS) {
                    $outcome = ['type' => 'reply', 'reply' => $response->content !== '' ? $response->content : 'I need direction to continue.'];
                }
            }
        }

        return $this->persistConsensusOutcome($project, $outcome, $lastResponse);
    }

    /** One tool-enabled Architect provider call, with usage recorded. */
    private function architectChat(Project $project, RoleBinding $binding, array $messages, array $tools): ProviderResponse
    {
        $response = $this->providers->forBinding($binding)->chat(new ProviderRequest(
            model: $binding->model,
            messages: $messages,
            maxTokens: $binding->maxTokens,
            temperature: $binding->temperature,
            tools: $tools,
            toolChoice: 'auto',
            topP: isset($binding->meta['top_p']) ? (float) $binding->meta['top_p'] : null,
            frequencyPenalty: isset($binding->meta['frequency_penalty']) ? (float) $binding->meta['frequency_penalty'] : null,
            presencePenalty: isset($binding->meta['presence_penalty']) ? (float) $binding->meta['presence_penalty'] : null,
            stop: isset($binding->meta['stop']) ? $binding->meta['stop'] : null,
            timeout: isset($binding->meta['timeout']) ? (int) $binding->meta['timeout'] : null,
        ));

        app(UsageLedger::class)->record($project, 'architect', $binding->model, $response->promptTokens, $response->completionTokens);

        return $response;
    }

    /**
     * The consensus tool surface (M15). read_file/list_repo are offered only when
     * the owner granted read access AND we are within the read-round budget.
     *
     * @return ToolDefinition[]
     */
    private function consensusTools(bool $withReads): array
    {
        $ask = new ToolDefinition(
            name: 'ask_owner',
            description: 'Ask the owner one or more specific, answerable questions whenever anything about the scope is ambiguous — never assume. Ends your turn until the owner answers.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'questions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'text' => ['type' => 'string', 'description' => 'One specific question.'],
                                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional answer choices.'],
                            ],
                            'required' => ['text'],
                        ],
                    ],
                ],
                'required' => ['questions'],
            ],
        );

        $propose = new ToolDefinition(
            name: 'propose_plan',
            description: 'Propose the plan once every question is answered and the scope is clear. Ends your turn; the owner must approve before anything is written.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'architecture_md' => ['type' => 'string', 'description' => 'Markdown: the target architecture as agreed.'],
                    'roadmap_md' => ['type' => 'string', 'description' => "Markdown roadmap. Milestones as '## M<N> — <title>' + one summary line, then tasks as '- [ ] T-00N — <title>'."],
                    'first_task_id' => ['type' => 'string', 'description' => 'The first task key, e.g. T-001.'],
                    'first_task_md' => ['type' => 'string', 'description' => 'Markdown brief for the first task: goal, acceptance criteria, files likely involved, test command.'],
                    'summary' => ['type' => 'string', 'description' => '2-3 sentences for the owner: what was agreed and what happens next.'],
                ],
                'required' => ['architecture_md', 'roadmap_md', 'first_task_id', 'first_task_md', 'summary'],
            ],
        );

        if (! $withReads) {
            return [$ask, $propose];
        }

        return [
            new ToolDefinition(
                name: 'read_file',
                description: 'Read the contents of one tracked file to ground your decisions. You have this capability — never ask the owner to paste a file.',
                parameters: [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => 'Repo-relative path of a tracked file.']],
                    'required' => ['path'],
                ],
            ),
            new ToolDefinition(
                name: 'list_repo',
                description: 'List tracked files: the whole tree (omit the argument) or one directory (a path ending in "/").',
                parameters: [
                    'type' => 'object',
                    'properties' => ['path' => ['type' => 'string', 'description' => 'Optional directory (e.g. "src/"). Omit for the full tree.']],
                ],
            ),
            $ask,
            $propose,
        ];
    }

    /** Fulfil a read_file/list_repo call, reusing the vetted tracked-only reader. */
    private function runReadTool(Project $project, ToolCall $call): string
    {
        $path = is_string($call->arguments['path'] ?? null) ? trim((string) $call->arguments['path']) : '';

        if ($call->name === 'read_file') {
            if ($path === '') {
                return 'read_file requires a "path".';
            }
            $req = [$path];
        } else { // list_repo
            $req = [$path === '' ? 'tree' : (str_ends_with($path, '/') ? $path : $path.'/')];
        }

        app(EventRecorder::class)->record($project, 'consensus.inspected', ['tool' => $call->name, 'paths' => $req], null, 'architect');

        return $this->gatherRepoContext($project, $req);
    }

    /**
     * @return array<int, array{text: string, options: ?array}>
     */
    private function parseAskOwner(ToolCall $call): array
    {
        $out = [];
        foreach (is_array($call->arguments['questions'] ?? null) ? $call->arguments['questions'] : [] as $q) {
            if (is_string($q) && trim($q) !== '') {
                $out[] = ['text' => trim($q), 'options' => null];
            } elseif (is_array($q) && is_string($q['text'] ?? null) && trim($q['text']) !== '') {
                $opts = $q['options'] ?? null;
                $out[] = [
                    'text' => trim($q['text']),
                    'options' => is_array($opts) && $opts !== [] ? array_values(array_filter($opts, 'is_string')) : null,
                ];
            }
        }

        return $out;
    }

    /** @return array{architecture_md: string, roadmap_md: string, first_task_id: string, first_task_md: string, summary: string} */
    private function normalizePlan(array $args): array
    {
        $taskId = is_string($args['first_task_id'] ?? null) && trim($args['first_task_id']) !== '' ? trim($args['first_task_id']) : 'T-001';

        return [
            'architecture_md' => (string) ($args['architecture_md'] ?? ''),
            'roadmap_md' => (string) ($args['roadmap_md'] ?? ''),
            'first_task_id' => $taskId,
            'first_task_md' => (string) ($args['first_task_md'] ?? ''),
            'summary' => (string) ($args['summary'] ?? ''),
        ];
    }

    /**
     * Persist the single human-visible outcome of a consensus turn and set state.
     * A propose_plan turn captures the plan in the message meta (not memory) — the
     * owner's approval (approvePlan) writes it. Every outcome ends NeedsYou.
     *
     * @param array{type: string, reply?: string, questions?: array, plan?: array} $outcome
     * @return array{message: ConsensusMessage, consensusPending: bool}
     */
    private function persistConsensusOutcome(Project $project, array $outcome, ProviderResponse $response): array
    {
        $consensusClaimed = $outcome['type'] === 'plan';

        $reply = trim((string) ($outcome['reply'] ?? ''));
        if ($reply === '') {
            $reply = match ($outcome['type']) {
                'questions' => 'I have a few questions before we go further.',
                'plan' => (string) ($outcome['plan']['summary'] ?? 'Here is the plan for your approval.'),
                default => '…',
            };
        }

        $meta = [
            'promptTokens' => $response->promptTokens,
            'completionTokens' => $response->completionTokens,
            'consensusClaimed' => $consensusClaimed,
        ];
        if ($outcome['type'] === 'plan') {
            $meta['proposed_plan'] = $outcome['plan'];
        }

        $message = $project->consensusMessages()->create([
            'role' => MessageRole::Architect,
            'content' => $reply,
            'meta' => $meta,
        ]);

        if ($outcome['type'] === 'questions') {
            foreach ($outcome['questions'] as $q) {
                $project->questions()->create([
                    'consensus_message_id' => $message->id,
                    'text' => $q['text'],
                    'options' => $q['options'],
                ]);
            }
        }

        app(EventRecorder::class)->record(
            $project,
            'consensus.message',
            [
                'questionsRaised' => $outcome['type'] === 'questions' ? count($outcome['questions']) : 0,
                'consensusClaimed' => $consensusClaimed,
                'messageId' => $message->id,
            ],
            null,
            'architect'
        );

        // The question gate: a plan only stands with zero open questions. Even
        // then nothing is written — the owner approves it (SPEC §3 phase 2 gate).
        $consensusPending = $consensusClaimed && $project->openQuestions()->count() === 0;

        $project->update(['status' => ProjectStatus::NeedsYou, 'last_activity_at' => now()]);

        return ['message' => $message, 'consensusPending' => $consensusPending];
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

        app(EventRecorder::class)->record(
            $question->project,
            'question.answered',
            ['questionId' => $question->id],
            null,
            'you'
        );

        if ($question->project->openQuestions()->count() === 0) {
            // All answered; the follow-up turn is about to run.
            $question->project->update(['status' => ProjectStatus::Working, 'last_activity_at' => now()]);
        }
    }

    /**
     * Phase 2, on the owner's explicit approval: write the plan the Architect
     * already captured via propose_plan (M15 — no second provider call; the plan
     * content came back structured in the tool arguments). Called from the
     * RunPlanDraft job. Salvage guards keep an unbuildable plan from writing an
     * empty brief the Builder can't act on.
     */
    public function approvePlan(Project $project): void
    {
        $plan = $this->capturedPlan($project);

        if ($plan === null) {
            $project->consensusMessages()->create([
                'role' => MessageRole::System,
                'content' => 'No proposed plan was found to approve — ask the Architect to propose the plan again in the chat.',
            ]);

            return;
        }

        // A plan without a real first task brief is unbuildable — salvage it
        // rather than writing an empty file the Builder can't act on.
        if (trim((string) ($plan['first_task_md'] ?? '')) === '') {
            $this->memory->write($project, 'plan_draft.md', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $project->consensusMessages()->create([
                'role' => MessageRole::System,
                'content' => 'The proposed plan had no first task brief — raw draft saved to plan_draft.md. Ask the Architect to propose a complete plan, then approve again.',
            ]);

            return;
        }

        $taskId = is_string($plan['first_task_id'] ?? null) && trim($plan['first_task_id']) !== ''
            ? trim($plan['first_task_id'])
            : 'T-001';

        $this->memory->write($project, 'architecture.md', (string) ($plan['architecture_md'] ?? ''));
        $this->memory->write($project, 'roadmap.md', (string) ($plan['roadmap_md'] ?? ''));
        $this->memory->write($project, "tasks/{$taskId}/task.md", (string) ($plan['first_task_md'] ?? ''));

        $project->consensusMessages()->create([
            'role' => MessageRole::System,
            'content' => "Consensus reached — project memory written to {$this->memory->pathFor($project)} "
                ."(architecture.md, roadmap.md, tasks/{$taskId}/task.md).\n\n"
                .(string) ($plan['summary'] ?? ''),
            'meta' => ['planWritten' => true, 'firstTaskId' => $taskId],
        ]);

        app(EventRecorder::class)->record(
            $project,
            'plan.written',
            ['firstTaskId' => $taskId],
            null,
            'architect'
        );

        app(\App\Projects\Roadmap\RoadmapSync::class)->for($project)->sync();

        // T-67: a greenfield repo gets an Architect-selected frontier-Builder
        // scaffold so the first Builder task starts on real ground.
        $this->bootstrapRepo($project);
    }

    /** The plan captured by the most recent propose_plan turn, or null. */
    private function capturedPlan(Project $project): ?array
    {
        $message = $project->consensusMessages()
            ->where('role', MessageRole::Architect)
            ->orderByDesc('id')
            ->get()
            ->first(fn ($m) => is_array($m->meta['proposed_plan'] ?? null));

        return $message?->meta['proposed_plan'] ?? null;
    }

    /**
     * Scaffold a greenfield repository (M14a/T-67, reconciled onto Builder
     * Selection in M14b). No-ops (returns false, no model call) on a repo that
     * already has tracked files.
     *
     * Under Builder Selection the Architect SELECTS the frontier Builder for the
     * scaffold; role separation still holds — the scaffold goes through the
     * **Reviewer** before it is committed (no self-approval). There is no HUMAN
     * gate (owner-locked: scaffolding an empty repo is obviously the right move),
     * but a rejected scaffold is retried once with the feedback and, if still
     * rejected, surfaced rather than committed. Scaffolding uses a dedicated
     * flow (direct file generation, owner-blessed) rather than the aider chain,
     * since there is no repo for aider to operate a worktree on yet. Returns
     * true only if a reviewed scaffold was committed.
     */
    public function bootstrapRepo(Project $project): bool
    {
        if ($this->repoIndex->fileList($project->repo_path) !== null) {
            return false; // not greenfield
        }

        // Builder Selection: greenfield scaffolding is a FRONTIER BUILDER action.
        app(EventRecorder::class)->record(
            $project,
            'build.builder_selected',
            ['strategy' => 'frontier', 'role' => 'frontier_builder', 'phase' => 'bootstrap'],
            null,
            'frontier_builder'
        );

        $scaffold = $this->generateScaffold($project, null);
        if ($scaffold === null) {
            app(EventRecorder::class)->record($project, 'repo.bootstrap_failed', ['reason' => 'no files'], null, 'frontier_builder');

            return false;
        }

        // Reviewer gate (no human gate): one corrective retry, then surface.
        $verdict = $this->reviewScaffold($project, $scaffold['files']);
        if (! $verdict->approved) {
            $retry = $this->generateScaffold($project, $this->scaffoldFeedback($verdict));
            if ($retry !== null) {
                $scaffold = $retry;
                $verdict = $this->reviewScaffold($project, $scaffold['files']);
            }
        }

        if (! $verdict->approved) {
            app(EventRecorder::class)->record($project, 'repo.bootstrap_review_rejected', ['summary' => $verdict->summary], null, 'reviewer');
            $project->consensusMessages()->create([
                'role' => MessageRole::System,
                'content' => "The project scaffold didn't pass review, so nothing was committed:\n\n> ".$verdict->summary
                    ."\n\nRefine the scope (Redefine milestones) and approve the plan again to retry the scaffold.",
                'meta' => ['bootstrap_rejected' => true],
            ]);

            return false;
        }

        $ok = app(\App\Projects\Repositories\CommitService::class)
            ->commitScaffold($project->repo_path, $scaffold['files'], $scaffold['message']);

        app(EventRecorder::class)->record(
            $project,
            $ok ? 'repo.bootstrapped' : 'repo.bootstrap_failed',
            ['files' => count($scaffold['files']), 'reviewed' => true],
            null,
            'frontier_builder'
        );

        if ($ok) {
            $project->consensusMessages()->create([
                'role' => MessageRole::System,
                'content' => 'The frontier Builder scaffolded the empty repository with '.count($scaffold['files'])
                    .' starter file(s), reviewed and committed, so the Builder has real ground for the first task.',
                'meta' => ['bootstrap' => true],
            ]);
        }

        return $ok;
    }

    /**
     * Generate a project scaffold from the frontier Builder binding (M14b). When
     * $feedback is set, this is the corrective retry after a review rejection.
     * Returns ['files' => [['path','contents'], …], 'message' => …] or null when
     * the model produced no usable files.
     *
     * @return array{files: array<int, array{path: string, contents: string}>, message: string}|null
     */
    private function generateScaffold(Project $project, ?string $feedback): ?array
    {
        $binding = app(RoleResolver::class)->resolve('frontier_builder', $project);
        $extraSystem = $binding->meta['system_prompt_extra'] ?? null;

        $prompt = self::BOOTSTRAP_PROMPT;
        if ($feedback !== null && trim($feedback) !== '') {
            $prompt .= "\n\nYour previous scaffold was REJECTED in review. Regenerate the FULL scaffold addressing this:\n".trim($feedback);
        }

        $response = $this->providers->forBinding($binding)->chat(new ProviderRequest(
            model: $binding->model,
            messages: array_merge($this->buildMessages($project, $extraSystem), [['role' => 'user', 'content' => $prompt]]),
            maxTokens: (int) config('majordom.architect.plan_max_tokens', 8000),
            temperature: $binding->temperature,
            jsonMode: true,
        ));

        app(UsageLedger::class)->record($project, 'frontier_builder', $binding->model, $response->promptTokens, $response->completionTokens);

        $data = json_decode(trim($response->content), true);
        $files = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $f) {
            if (is_array($f) && is_string($f['path'] ?? null) && trim($f['path']) !== '' && array_key_exists('contents', $f)) {
                $files[] = ['path' => trim($f['path']), 'contents' => (string) $f['contents']];
            }
        }

        if ($files === []) {
            return null;
        }

        $message = is_string($data['commit_message'] ?? null) && trim($data['commit_message']) !== ''
            ? $data['commit_message']
            : 'chore: scaffold project structure';

        return ['files' => $files, 'message' => $message];
    }

    /**
     * Run the Reviewer over a proposed (uncommitted) scaffold. Uses an ephemeral
     * Task (not persisted — the scaffold isn't a roadmap task) and a synthetic
     * added-files diff so the standard ReviewerService can judge it.
     */
    private function reviewScaffold(Project $project, array $files): \App\Agents\Reviewer\ReviewVerdict
    {
        $this->memory->write($project, 'tasks/__bootstrap__/task.md', self::BOOTSTRAP_REVIEW_BRIEF);

        $task = new Task(['task_key' => '__bootstrap__', 'title' => 'Project scaffold']);
        $task->project_id = $project->id;
        $task->setRelation('project', $project);

        return app(\App\Agents\Reviewer\ReviewerService::class)->review($task, $this->scaffoldDiff($files), null);
    }

    /** Render proposed scaffold files as a synthetic unified diff of additions. */
    private function scaffoldDiff(array $files): string
    {
        $blocks = [];
        foreach ($files as $f) {
            $path = $f['path'];
            $added = implode("\n", array_map(fn ($l) => '+'.$l, explode("\n", (string) $f['contents'])));
            $blocks[] = "diff --git a/{$path} b/{$path}\nnew file mode 100644\n--- /dev/null\n+++ b/{$path}\n{$added}";
        }

        return implode("\n\n", $blocks);
    }

    private function scaffoldFeedback(\App\Agents\Reviewer\ReviewVerdict $verdict): string
    {
        $lines = [$verdict->summary];
        foreach ($verdict->comments as $c) {
            $lines[] = '- '.($c['file'] ? $c['file'].': ' : '').$c['comment'];
        }

        return implode("\n", array_filter($lines));
    }

    private const BOOTSTRAP_REVIEW_BRIEF = <<<'MD'
# Project scaffold (bootstrap)

This is the INITIAL scaffold of a greenfield repository — foundation only, not a
feature. Judge whether it is a sound, runnable starting point for the agreed
architecture: sensible directory layout, dependency/package manifests, a README,
test runner/config, and an entry point.

Approve when the scaffold is coherent and consistent with the architecture. Do
NOT reject for missing feature logic, missing tests of behavior, or incomplete
functionality — those are for later tasks. Reject only for real problems:
malformed manifests, broken structure, missing essential foundation, or
contents that contradict the agreed architecture.
MD;

    /**
     * Decompose a roadmap task into a concrete `task.md` brief (SPEC §3 phase 3).
     *
     * The plan (approvePlan) only writes the FIRST task's brief; every later
     * task exists as a roadmap row with just a title. This turns that title
     * into a buildable brief on demand — the engine that lets a milestone's
     * tasks chain (M12). Writes nothing and returns early if a non-empty brief
     * already exists, so it is safe to call before each build.
     */
    public function decomposeTask(Project $project, Task $task): void
    {
        $briefPath = "tasks/{$task->task_key}/task.md";
        if ($this->memory->exists($project, $briefPath)
            && trim((string) $this->memory->read($project, $briefPath)) !== '') {
            return; // already has a brief (first task, or a prior decompose)
        }

        $binding = app(RoleResolver::class)->resolve('architect', $project);
        $extraSystem = $binding->meta['system_prompt_extra'] ?? null;

        $system = self::DECOMPOSE_PROMPT;
        if ($extraSystem !== null && trim($extraSystem) !== '') {
            $system .= "\n\n".trim($extraSystem);
        }

        $response = $this->providers->forBinding($binding)->chat(new ProviderRequest(
            model: $binding->model,
            messages: [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $this->decomposeContext($project, $task)],
            ],
            maxTokens: (int) config('majordom.architect.plan_max_tokens', 8000),
            temperature: $binding->temperature,
            jsonMode: false, // a task.md brief is markdown, not JSON
            topP: isset($binding->meta['top_p']) ? (float) $binding->meta['top_p'] : null,
            frequencyPenalty: isset($binding->meta['frequency_penalty']) ? (float) $binding->meta['frequency_penalty'] : null,
            presencePenalty: isset($binding->meta['presence_penalty']) ? (float) $binding->meta['presence_penalty'] : null,
            stop: isset($binding->meta['stop']) ? $binding->meta['stop'] : null,
            timeout: isset($binding->meta['timeout']) ? (int) $binding->meta['timeout'] : null,
        ));

        app(UsageLedger::class)->record(
            $project,
            'architect',
            $binding->model,
            $response->promptTokens,
            $response->completionTokens
        );

        $brief = trim($response->content);
        if ($brief === '') {
            // Never write an empty brief the Builder can't act on — surface it.
            app(EventRecorder::class)->record(
                $project,
                'task.decompose_failed',
                ['task_key' => $task->task_key],
                null,
                'architect'
            );

            return;
        }

        $this->memory->write($project, $briefPath, $brief);

        app(EventRecorder::class)->record(
            $project,
            'task.decomposed',
            ['task_key' => $task->task_key],
            null,
            'architect'
        );
    }

    /**
     * "Add context" (post-plan steering): fold an owner note/constraint into
     * durable project memory (decisions.md) so every FUTURE task brief inherits
     * it — see decomposeContext(). No LLM call, no re-planning; a task already
     * mid-build won't retroactively get it.
     */
    public function addContext(Project $project, string $note): void
    {
        $note = trim($note);
        if ($note === '') {
            return;
        }

        $existing = $this->memory->read($project, 'decisions.md') ?? "# Decisions & added context\n";
        $this->memory->write(
            $project,
            'decisions.md',
            rtrim($existing)."\n\n## Added ".now()->toDateString()."\n{$note}\n",
        );

        $project->consensusMessages()->create([
            'role' => MessageRole::System,
            'content' => "✓ Context added — future task briefs will honor it:\n\n> ".$note,
        ]);

        app(EventRecorder::class)->record($project, 'context.added', [], null, 'you');
    }

    /**
     * "Redefine milestones / specs" (the lightweight amend): the Architect
     * revises roadmap.md (+ architecture.md) from the owner's instruction and
     * re-syncs. Keys stay stable so built work is preserved (RoadmapSync upserts
     * by key). One provider turn.
     */
    public function redefinePlan(Project $project, string $instruction): void
    {
        $instruction = trim($instruction);
        if ($instruction === '') {
            return;
        }

        $binding = app(RoleResolver::class)->resolve('architect', $project);
        $current = $this->memory->read($project, 'roadmap.md') ?? '(none yet)';
        $architecture = $this->memory->read($project, 'architecture.md') ?? '(none yet)';

        $response = $this->providers->forBinding($binding)->chat(new ProviderRequest(
            model: $binding->model,
            messages: [
                ['role' => 'system', 'content' => self::REDEFINE_PROMPT],
                ['role' => 'user', 'content' => "## Current roadmap.md\n{$current}\n\n## Current architecture.md\n{$architecture}\n\n## Owner change request\n{$instruction}"],
            ],
            maxTokens: (int) config('majordom.architect.plan_max_tokens', 8000),
            temperature: $binding->temperature,
            jsonMode: true,
        ));

        app(UsageLedger::class)->record($project, 'architect', $binding->model, $response->promptTokens, $response->completionTokens);

        $data = json_decode(trim($response->content), true);
        if (! is_array($data) || empty($data['roadmap_md'])) {
            $project->consensusMessages()->create([
                'role' => MessageRole::System,
                'content' => 'The roadmap revision came back malformed — nothing changed. Try rephrasing the request.',
            ]);

            return;
        }

        $this->memory->write($project, 'roadmap.md', (string) $data['roadmap_md']);
        if (! empty($data['architecture_md'])) {
            $this->memory->write($project, 'architecture.md', (string) $data['architecture_md']);
        }

        app(\App\Projects\Roadmap\RoadmapSync::class)->for($project)->sync();

        // T-62: the revised plan must not resume the old, possibly-poisoned
        // cycle — reset the execution loop, then name the task the loop restarts
        // from (its first still-pending task in roadmap order).
        $firstTaskKey = app(\App\Core\Workflow\WorkflowEngine::class)->resetForRedefine($project);

        // Real recovery, not a replay: the restart task's brief was written
        // against the OLD roadmap (the poisoned brief the owner redefined away).
        // Regenerate it from the revised roadmap so "Start build" launches fresh
        // — DelegateNode reads task.md directly and never re-decomposes, so an
        // un-refreshed brief would rebuild the exact thing being escaped.
        if ($firstTaskKey !== null) {
            $this->regenerateBriefForRestart($project, $firstTaskKey);
        }

        // Re-arm the "Start build" trigger: getPlannedTaskProperty needs the
        // planWritten message to carry firstTaskId (approvePlan sets it; the old
        // redefine path did not — that was the "redefine didn't restart" bug).
        $project->consensusMessages()->create([
            'role' => MessageRole::System,
            'content' => "✓ Plan updated — ".(string) ($data['summary'] ?? 'the roadmap was revised.')
                .($firstTaskKey !== null
                    ? "\n\nThe build loop was reset. Use **Start build** to relaunch from {$firstTaskKey} with the revised brief."
                    : "\n\nNo tasks remain to build."),
            'meta' => array_filter([
                'planWritten' => true,
                'firstTaskId' => $firstTaskKey,
            ], fn ($v) => $v !== null),
        ]);

        app(EventRecorder::class)->record($project, 'plan.redefined', ['firstTaskId' => $firstTaskKey], null, 'architect');
    }

    /**
     * Regenerate a task's build brief from the CURRENT roadmap so a restart
     * doesn't rebuild the stale (possibly poisoned) brief (M14a/T-62, reused by
     * the failed-task retry recovery in M14b). decomposeTask skips when a
     * non-empty brief exists, so clear it first; if regeneration comes back
     * empty, restore the prior brief so the restart is never blocked on a
     * missing task.md.
     */
    public function refreshTaskBrief(Project $project, string $taskKey): void
    {
        $this->regenerateBriefForRestart($project, $taskKey);
    }

    private function regenerateBriefForRestart(Project $project, string $taskKey): void
    {
        $task = $project->tasks()->where('task_key', $taskKey)->latest('id')->first();
        if ($task === null) {
            return;
        }

        $briefPath = "tasks/{$taskKey}/task.md";
        $stale = (string) $this->memory->read($project, $briefPath);

        $this->memory->write($project, $briefPath, ''); // force decompose to regenerate
        $this->decomposeTask($project, $task);

        if (trim((string) $this->memory->read($project, $briefPath)) === '' && trim($stale) !== '') {
            $this->memory->write($project, $briefPath, $stale); // regen failed — keep something buildable
        }
    }

    private const REDEFINE_PROMPT = <<<'PROMPT'
You are the Architect revising an EXISTING project roadmap per the owner's
change request. Output ONLY a JSON object:
{
  "roadmap_md": "the FULL updated roadmap markdown",
  "architecture_md": "the updated architecture markdown, or omit if unchanged",
  "summary": "1-2 sentences: what changed"
}

The roadmap format is milestones `## M<N> — <title>` each followed by one
summary line, then tasks as checkboxes `- [ ] T-00N — <title>`.

HARD RULES — you are AMENDING, not rewriting from scratch:
- Preserve every existing milestone key (M1, M2 …) and task key (T-001 …). Do
  NOT renumber, and do NOT delete a task that may already be built — reword or
  reorder if needed, but keep its key.
- Preserve `[x]`/`[~]` checkbox marks on tasks that already have them (done /
  in-progress work must not regress to unchecked).
- Add new milestones/tasks with the NEXT available keys.
- Make the smallest change that satisfies the request. Keep everything else identical.
PROMPT;

    /**
     * Assemble the decompose context: the milestone goal, this task's line, the
     * project architecture, and a short trail of already-built sibling tasks so
     * the new brief fits what came before (no duplicate work, consistent style).
     */
    /**
     * Fetch the repo context the Architect requested (M14a/T-66). Entries are
     * tracked file paths, a directory ("dir/") for its listing, or "tree" for
     * the full tracked tree. Enforces tracked-only reads (membership against the
     * git file list) so gitignored secrets like .env can never be pulled into
     * the model, on top of RepoIndex::readFile's in-repo confinement. Capped.
     *
     * @param string[] $reads
     */
    private function gatherRepoContext(Project $project, array $reads): string
    {
        $treeAll = $this->repoIndex->fileList($project->repo_path, 2000);
        $tracked = $treeAll !== null
            ? array_values(array_filter(array_map('trim', explode("\n", $treeAll))))
            : [];
        // Drop the "+N more" footer line from the membership set if present.
        $trackedSet = array_flip(array_filter($tracked, fn ($f) => ! str_starts_with($f, '…')));

        $blocks = [];
        $budget = self::GATHER_MAX_TOTAL;

        foreach ($reads as $req) {
            if (! is_string($req) || $budget <= 0) {
                continue;
            }
            $req = trim($req);
            if ($req === '') {
                continue;
            }

            if (in_array($req, ['tree', '**', '.', './'], true)) {
                $block = "### Repository tree (tracked)\n".($treeAll ?? '(empty — no tracked files)');
            } elseif (str_ends_with($req, '/')) {
                $prefix = ltrim($req, '/');
                $under = array_values(array_filter(array_keys($trackedSet), fn ($f) => str_starts_with($f, $prefix)));
                $block = "### {$req} (tracked files)\n".($under === [] ? '(no tracked files here)' : implode("\n", array_slice($under, 0, 200)));
            } else {
                $rel = ltrim($req, '/');
                if (! isset($trackedSet[$rel])) {
                    $block = "### {$req}\n(not a tracked file — cannot read; consult the tree)";
                } else {
                    $contents = $this->repoIndex->readFile($project->repo_path, $rel, self::READ_MAX_BYTES);
                    $block = "### {$req}\n".($contents ?? '(unreadable)');
                }
            }

            $block = mb_substr($block, 0, $budget);
            $budget -= mb_strlen($block);
            $blocks[] = $block;
        }

        return $blocks === [] ? '(nothing to show)' : implode("\n\n", $blocks);
    }

    private function decomposeContext(Project $project, Task $task): string
    {
        $milestone = $task->milestone;
        $roadmap = $this->memory->read($project, 'roadmap.md') ?? '(no roadmap.md)';
        $architecture = $this->memory->read($project, 'architecture.md') ?? '(no architecture.md yet)';
        $style = $this->memory->read($project, 'coding_style.md');
        // Owner-added context/constraints (the "Add context" action) must reach
        // the Builder — this is where it does, folded into every future brief.
        $decisions = $this->memory->read($project, 'decisions.md');

        $milestoneLine = $milestone
            ? "{$milestone->milestone_key} — {$milestone->title}".($milestone->summary ? "\nGoal: {$milestone->summary}" : '')
            : '(no milestone)';

        // Prior tasks in the same milestone that already have a brief/handoff —
        // give the builder continuity without dumping whole diffs.
        $prior = '';
        if ($milestone) {
            $siblings = $milestone->tasks()
                ->where('position', '<', $task->position ?? PHP_INT_MAX)
                ->orderBy('position')
                ->get();
            foreach ($siblings as $s) {
                $handoff = $this->memory->read($project, "tasks/{$s->task_key}/handoff.md");
                $line = "- {$s->task_key}: {$s->title}";
                if ($handoff) {
                    $line .= "\n  handoff: ".Str::limit(trim(strip_tags($handoff)), 400);
                }
                $prior .= $line."\n";
            }
        }
        $prior = $prior !== '' ? $prior : '(none yet — this is the milestone\'s first task)';

        $styleBlock = $style ? "\n\n## Project coding style\n{$style}" : '';
        $decisionsBlock = ($decisions && trim($decisions) !== '')
            ? "\n\n## Owner decisions & added context (MUST honor)\n{$decisions}"
            : '';

        // Grounding (e2e #2): real tracked paths so "Files likely involved"
        // names files that exist, and an explicit test-command contract so
        // acceptance criteria never invent a test runner the repo lacks.
        $tree = $this->repoIndex->fileList($project->repo_path);
        $treeBlock = $tree
            ? "\n\n## Repository files (tracked — ground your paths in these)\n{$tree}"
            : '';
        $testCommand = $project->test_command;
        $testBlock = ($testCommand && trim($testCommand) !== '')
            ? "\n\n## Test command\n`{$testCommand}` — acceptance criteria may require it to pass."
            : "\n\n## Test command\nNONE — this project has NO automated test runner. Do NOT write acceptance criteria that require running tests; every criterion must be checkable by inspecting files or observing behavior.";

        return <<<CONTEXT
Write the build brief for this task:

## Task
{$task->task_key} — {$task->title}

## Its milestone
{$milestoneLine}

## Already completed in this milestone
{$prior}

## Project architecture
{$architecture}

## Full roadmap (for context only — decompose ONLY the task above)
{$roadmap}{$treeBlock}{$testBlock}{$styleBlock}{$decisionsBlock}
CONTEXT;
    }

    private const DECOMPOSE_PROMPT = <<<'PROMPT'
You are the Architect. Decompose ONE roadmap task into a precise build brief for
a local coding model driven by an automated harness. The builder has only your
brief and the repository — be concrete and self-contained.

Respond with GitHub-flavored markdown ONLY (no preamble, no code fences around
the whole thing), in exactly this shape:

# <task title>

## Goal
2-4 sentences: what this task must achieve and why, in the context of the milestone.

## Acceptance criteria
- Bullet list of concrete, falsifiable outcomes. Prefer observable behavior and
  specific file/function names. Only reference the Test command given in the
  context — if it says NONE, no criterion may require running tests, and you
  must NEVER invent a test command.

## Files likely involved
- Relative paths the builder will create or edit (best-effort; the builder may adjust).

## Notes
- Any constraints, gotchas, or dependencies on prior tasks. Keep it tight.

Rules: scope strictly to THIS task — do not implement later roadmap items. Assume
prior tasks in the milestone are already done. Do not invent requirements the
roadmap/architecture don't support. No questions — produce the brief.
PROMPT;

    /** @return array<int, array{role: string, content: string}> */
    private function buildMessages(Project $project, ?string $extraSystemPrompt = null): array
    {
        $messages = [[
            'role' => 'system',
            'content' => $this->systemPrompt($project, $extraSystemPrompt),
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

    private function systemPrompt(Project $project, ?string $extraSystemPrompt = null): string
    {
        $open = $project->openQuestions()->pluck('text')->all();
        $openBlock = $open === []
            ? 'There are currently no unanswered questions.'
            : "Unanswered questions you have already raised (do NOT re-raise them):\n- ".implode("\n- ", $open);

        // Grounding (e2e#3): let the Architect reason about the REAL repository
        // during consensus — so it grounds questions in what exists, and
        // recognizes a greenfield repo that must be scaffolded before any task.
        $canRead = $project->capability()->canRead();
        $tree = $this->repoIndex->fileList($project->repo_path);
        if (! $tree) {
            $repoBlock = "Repository state: this repository is EMPTY (no tracked files yet) — the project is greenfield. The first work will be scaffolding the project structure; factor that into the scope you agree on.";
        } elseif ($canRead) {
            $repoBlock = "Repository files (tracked — ground your questions and scope in these real paths; do not invent files):\n{$tree}\n\n"
                ."Use the read_file tool to see any file's contents and list_repo to browse directories — never ask the owner to paste a file.";
        } else {
            $repoBlock = "Repository files (tracked — names only; ground your scope in these real paths, do not invent files):\n{$tree}\n\n"
                ."You do NOT have read access to file CONTENTS in this project (the owner has not granted it). When you need to see inside a file, ask the owner to share it.";
        }

        $prompt = <<<PROMPT
You are the Architect of the software project "{$project->name}" (repository: {$project->repo_path}).
Your single goal in this conversation is to reach consensus with the human owner on WHAT to build — before any plan is made.

Non-negotiable mandate: surface EVERY open question before proposing anything. Ask, never assume. Questions must be discrete, answerable items — not prose musings.

How you act each turn — through your tools:
- To inspect the repository, call read_file or list_repo. The engine fulfils the read and hands you the contents; then continue. Never ask permission to read, and never ask the owner to paste a file.
- When anything about the scope is ambiguous, call ask_owner with specific questions. This ends your turn until the owner answers.
- When every question is answered and the scope is clear, call propose_plan (architecture, roadmap, first task brief, summary). The owner must approve before anything is written.
- If you are only replying conversationally (acknowledging, thinking aloud), just write text — it is the owner's turn next.

You may only propose_plan once every question you ever raised has been answered. The owner may answer in their own words or defer to you ("your call"); treat a deferral as a real answer — decide, state it, and don't re-ask. Prefer the project's own summary docs (architecture.md, roadmap.md, decisions.md) when they exist; only read source when you need specifics.

{$openBlock}

{$repoBlock}
PROMPT;

        if ($extraSystemPrompt !== null && trim($extraSystemPrompt) !== '') {
            $prompt .= "\n\n" . trim($extraSystemPrompt);
        }

        return $prompt;
    }
}
