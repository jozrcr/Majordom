<?php

namespace App\Agents\Architect;

use App\Agents\Providers\ProviderRegistry;
use App\Agents\Providers\ProviderRequest;
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
use App\Support\RoleResolver;
use Illuminate\Support\Str;

/**
 * The Architect's consensus orchestration (SPEC §3 phase 1–2, M2 slice).
 *
 * The ask-all-questions mandate is enforced twice: in the system prompt, and
 * mechanically here — consensus_reached is ignored while any question is open
 * or the same turn raised new ones. The model cannot talk its way past the gate.
 */
class ArchitectService
{
    /** Max repo-inspection round-trips the Architect may self-drive per turn (M14a/T-66). */
    private const MAX_INSPECT_ROUNDS = 2;

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
     * One conversation turn. $userMessage may be null when re-prompting after
     * answers were recorded. When the turn closed consensus,
     * 'consensusPending' is true and the plan awaits the owner's explicit
     * approval (approvePlan()) — nothing is written yet.
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

        // Bounded self-inspection (M14a/T-66): the Architect may request repo
        // context (tracked files, a "dir/" listing, or "tree") and continue on
        // its own — up to MAX_INSPECT_ROUNDS — before it must ask a question or
        // conclude. Beyond the cap it falls through to the normal handling (a
        // still-empty turn surfaces as a T-65 stall the owner can nudge).
        $rounds = 0;
        while (true) {
            $response = $this->providers->forBinding($binding)->chat(new ProviderRequest(
                model: $binding->model,
                messages: $this->buildMessages($project, $extraSystem),
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
                'architect',
                $binding->model,
                $response->promptTokens,
                $response->completionTokens
            );

            $envelope = ArchitectEnvelope::fromContent($response->content);

            // Only a PURE inspection request loops (no question, no consensus) —
            // a turn that asks or concludes is honored immediately even if it
            // also listed reads, so a real question always reaches the owner.
            // Reads are an OPT-IN capability (M14b): if the owner hasn't granted
            // read access, the Architect's `reads` are ignored and it must ask.
            $wantsInspection = $envelope->reads !== []
                && $envelope->questions === []
                && ! $envelope->consensusReached
                && $project->capability()->canRead();

            if ($wantsInspection && $rounds < self::MAX_INSPECT_ROUNDS) {
                $context = $this->gatherRepoContext($project, $envelope->reads);
                $project->consensusMessages()->create([
                    'role' => MessageRole::System,
                    'content' => '[repo inspection: '.implode(', ', $envelope->reads)."]\n\n".$context,
                    'meta' => ['inspection' => true, 'paths' => array_values($envelope->reads)],
                ]);
                app(EventRecorder::class)->record(
                    $project,
                    'consensus.inspected',
                    ['paths' => array_values($envelope->reads)],
                    null,
                    'architect'
                );
                $rounds++;

                continue;
            }

            break;
        }

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

        app(EventRecorder::class)->record(
            $project,
            'consensus.message',
            [
                'questionsRaised' => count($envelope->questions),
                'consensusClaimed' => $envelope->consensusReached,
                'messageId' => $message->id,
            ],
            null,
            'architect'
        );

        // The question gate: a consensus claim only stands with zero open
        // questions — including ones raised this very turn. Even then the
        // plan is NOT drafted here: that is the human's call (SPEC §3 phase 2
        // plan-approval gate; always blocking until autonomy profiles land).
        $openCount = $project->openQuestions()->count();
        $consensusPending = $envelope->consensusReached && $openCount === 0;

        // Never-stall invariant (M14a, e2e#3): a turn that raises no question
        // and does not reach consensus used to drop the project to a silent
        // Idle with an unaddressed reply — the "stalls after Q&A" dead end.
        // Every consensus turn actually ends with the owner as the next actor
        // (answer a question · approve the plan · steer/nudge a stall), so the
        // status is always NeedsYou; a stall additionally emits an event so the
        // workspace can offer a one-click nudge to recover.
        $stalled = $openCount === 0 && ! $consensusPending;

        if ($stalled) {
            app(EventRecorder::class)->record(
                $project,
                'consensus.stalled',
                ['messageId' => $message->id],
                null,
                'architect'
            );
        }

        $project->update([
            'status' => ProjectStatus::NeedsYou,
            'last_activity_at' => now(),
        ]);

        return ['message' => $message, 'consensusPending' => $consensusPending, 'stalled' => $stalled];
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
     * Phase 2, on the owner's explicit approval: distill the agreed intent
     * into project memory files. Called from the RunPlanDraft job — this
     * makes a provider call and must never run inside a web request.
     */
    public function approvePlan(Project $project): void
    {
        $binding = app(RoleResolver::class)->resolve('architect', $project);

        $extraSystem = $binding->meta['system_prompt_extra'] ?? null;
        $response = $this->providers->forBinding($binding)->chat(new ProviderRequest(
            model: $binding->model,
            messages: array_merge($this->buildMessages($project, $extraSystem), [[
                'role' => 'user',
                'content' => self::PLAN_PROMPT,
            ]]),
            maxTokens: (int) config('majordom.architect.plan_max_tokens', 8000),
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
            'architect',
            $binding->model,
            $response->promptTokens,
            $response->completionTokens
        );

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

        // A plan without a real first task brief is malformed — salvage it
        // rather than writing an empty file the Builder can't act on
        // (bit a legacy project on 2026-07-15).
        if (trim((string) ($data['first_task_md'] ?? '')) === '') {
            $this->memory->write($project, 'plan_draft.md', $response->content);
            $project->consensusMessages()->create([
                'role' => MessageRole::System,
                'content' => 'The plan came back without a first task brief — raw draft saved to plan_draft.md. Approve the plan again to retry.',
            ]);

            return;
        }

        $this->memory->write($project, 'architecture.md', (string) ($data['architecture_md'] ?? ''));
        $this->memory->write($project, 'roadmap.md', (string) ($data['roadmap_md'] ?? ''));
        $this->memory->write($project, "tasks/{$taskId}/task.md", (string) ($data['first_task_md'] ?? ''));

        $project->consensusMessages()->create([
            'role' => MessageRole::System,
            'content' => "Consensus reached — project memory written to {$this->memory->pathFor($project)} "
                ."(architecture.md, roadmap.md, tasks/{$taskId}/task.md).\n\n"
                .(string) ($data['summary'] ?? ''),
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

        // T-67: a greenfield repo gets an Architect-authored scaffold so the
        // first Builder task starts on real ground instead of an empty directory.
        $this->bootstrapRepo($project);
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
     * Regenerate a task's build brief from the CURRENT (revised) roadmap so a
     * redefine restart doesn't rebuild the stale brief (M14a/T-62). decomposeTask
     * skips when a non-empty brief exists, so clear it first; if regeneration
     * comes back empty, restore the prior brief so the restart is never blocked
     * on a missing task.md.
     */
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
        // during consensus, not just its path — so it grounds questions in what
        // exists instead of stalling for lack of context, and recognizes a
        // greenfield repo that must be scaffolded before any Builder task.
        $canRead = $project->capability()->canRead();
        $tree = $this->repoIndex->fileList($project->repo_path);
        if (! $tree) {
            $repoBlock = "Repository state: this repository is EMPTY (no tracked files yet) — the project is greenfield. The first work will be scaffolding the project structure; factor that into the scope you agree on.";
        } elseif ($canRead) {
            $repoBlock = "Repository files (tracked — ground your questions and scope in these real paths; do not invent files):\n{$tree}\n\n"
                ."YOU CAN READ THESE FILES DIRECTLY. This is not a plain chat — the engine fulfills file reads for you. "
                ."To see any file's CONTENTS, list its path in the \"reads\" array of your JSON reply and you will be handed the "
                ."contents on your very next turn. You do NOT need permission, and you must NEVER ask the owner to paste a file, "
                ."NEVER ask \"may I read …?\", and NEVER claim you lack filesystem access — you have it, through \"reads\".";
        } else {
            // Reads not granted (M14b opt-in rights): be honest — the Architect
            // must ask the owner to share contents; "reads" will be ignored.
            $repoBlock = "Repository files (tracked — names only; ground your scope in these real paths, do not invent files):\n{$tree}\n\n"
                ."You can see the file NAMES above but do NOT have read access to their CONTENTS in this project "
                ."(the owner has not granted it). When you need to see inside a file, ask the owner to share it — the \"reads\" field will not be fulfilled.";
        }

        $prompt = <<<PROMPT
You are the Architect of the software project "{$project->name}" (repository: {$project->repo_path}).
Your single goal in this conversation is to reach consensus with the human owner on WHAT to build — before any plan is made.

Non-negotiable mandate: surface EVERY open question before proposing anything. Ask, never assume. Questions must be discrete, answerable items — not prose musings.

{$openBlock}

{$repoBlock}

You must respond ONLY with a JSON object of this exact shape (no markdown fences, no text outside the JSON):
{
  "reply": "markdown text shown to the owner — your reasoning, acknowledgements, current understanding",
  "questions": [{"text": "one specific question", "options": ["optional", "answer", "choices"]}],
  "consensus_reached": false,
  "reads": ["optional/repo/path.php", "some/dir/", "tree"]
}

To inspect files, your reply looks EXACTLY like this — no permission question, just the read:
{
  "reply": "Let me check the current dependency versions before finalizing scope.",
  "questions": [],
  "consensus_reached": false,
  "reads": ["composer.json", "package.json"]
}
On the next turn you will receive those file contents as a system note; then continue.

Rules:
1. New ambiguities go in "questions" — one entry per question, never buried in "reply".
2. "consensus_reached" may only be true when every question you ever raised has been answered AND this turn raises none. The engine enforces this regardless of what you claim.
3. When consensus_reached is true, "reply" must restate the agreed scope in a few sentences.
4. Keep "reply" concise; the owner reads it in a chat pane.
5. The owner may answer a question in their own words instead of picking an option — including deferring to you ("your call"). Treat a deferral as a real answer: make a sensible decision, state it explicitly in "reply", and do not re-ask.
6. When you need to see the ACTUAL contents of files to decide, READ THEM — put the repo-relative paths in "reads". Do this yourself; it is never a question and never needs approval. You may request several at once, a directory (path ending in "/") for its listing, or "tree" for the whole file tree. On an inspection turn leave "questions" empty and "consensus_reached" false — you'll be given the contents and can continue. You have a limited number of inspection rounds per turn, so gather what you need, then ask or conclude. Prefer the project's own summary docs (architecture.md, roadmap.md, decisions.md) when they exist; only read source when you genuinely need specifics. Only tracked files are readable (no secrets). FORBIDDEN: asking the owner to paste a file, asking permission to read ("may I check …?"), or claiming you have no file access — all three are always wrong; emit "reads" instead. If the owner ever tells you to go check / read the repo, your reply that turn MUST carry the paths in "reads".
PROMPT;

        if ($extraSystemPrompt !== null && trim($extraSystemPrompt) !== '') {
            $prompt .= "\n\n" . trim($extraSystemPrompt);
        }

        return $prompt;
    }

    private const PLAN_PROMPT = <<<'PROMPT'
Consensus is reached. Produce the initial project memory now.
Respond ONLY with a JSON object of this exact shape:
{
  "architecture_md": "markdown — the target repo's architecture as you understand it",
  "roadmap_md": "markdown roadmap. Each milestone is a header '## M<N> — <title>' followed by one summary line, then its tasks as checkbox items '- [ ] T-00N — <task title>'. The first task MUST appear as the first checkbox and match first_task_id. Example:\n## M1 — Skeleton\nStand up the project shell.\n- [ ] T-001 — Create repo structure\n- [ ] T-002 — Add build system",
  "first_task_id": "T-001",
  "first_task_md": "markdown — the first task brief: goal, acceptance criteria, files likely involved, test command",
  "summary": "2-3 sentences for the owner: what was agreed and what happens next"
}
PROMPT;
}
