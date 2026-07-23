<?php

use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Enums\MessageRole;
use App\Enums\QuestionStatus;
use App\Models\Project;
use App\Projects\Memory\MemoryStore;

class ScriptedProvider implements Provider
{
    public array $requests = [];

    /** @param array<int, string|ProviderResponse> $responses */
    public function __construct(public array $responses) {}

    public function chat(ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;
        $next = array_shift($this->responses);

        // A scripted ProviderResponse (tool calls, M15) passes through; a bare
        // string is a plain-text reply; nothing left ⇒ an empty reply.
        if ($next instanceof ProviderResponse) {
            return $next;
        }

        return new ProviderResponse($next ?? '', 'stop', 10, 20);
    }
}

beforeEach(function () {
    $this->memoryRoot = sys_get_temp_dir().'/majordom-arch-'.uniqid();
    config(['majordom.memory_root' => $this->memoryRoot]);
    $this->project = Project::factory()->create();
});

afterEach(function () {
    if (is_dir($this->memoryRoot)) {
        exec('rm -rf '.escapeshellarg($this->memoryRoot));
    }
});

function architect(array $responses): array
{
    $provider = new ScriptedProvider($responses);
    app()->instance(\App\Agents\Providers\Provider::class, $provider);

    return [new ArchitectService(app(\App\Agents\Providers\ProviderRegistry::class), MemoryStore::fromConfig(), app(\App\Projects\Repositories\RepoIndex::class)), $provider];
}

it('persists user + architect messages and creates questions from ask_owner', function () {
    [$service] = architect([archAsk([
        ['text' => 'Which auth method?', 'options' => ['token', 'oauth']],
        ['text' => 'Is SQLite acceptable?'],
    ], 'I need to know two things first.')]);

    $result = $service->converse($this->project, 'Build me a login page');

    expect($result['consensusPending'])->toBeFalse()
        ->and($this->project->consensusMessages()->count())->toBe(2) // user + architect
        ->and($this->project->openQuestions()->count())->toBe(2);

    $q = $this->project->questions()->first();
    expect($q->options)->toBe(['token', 'oauth'])
        ->and($q->consensus_message_id)->toBe($result['message']->id);
});

it('asking questions never marks consensus pending and writes no memory', function () {
    [$service] = architect([archAsk([['text' => 'One more thing?']], 'Almost there…')]);

    $result = $service->converse($this->project, 'Go');

    expect($result['consensusPending'])->toBeFalse()
        ->and(is_dir($this->memoryRoot))->toBeFalse();
});

it('gates a plan proposed while a previously raised question is still open', function () {
    $this->project->questions()->create(['text' => 'Still open?']);

    [$service] = architect([archPropose(samplePlan())]);

    expect($service->converse($this->project, 'Proceed')['consensusPending'])->toBeFalse();
});

it('reports consensus pending and does NOT write the plan without approval', function () {
    [$service, $provider] = architect([archPropose(samplePlan(), 'Agreed scope: X.')]);

    $result = $service->converse($this->project, 'All agreed.');

    expect($result['consensusPending'])->toBeTrue()
        ->and(is_dir($this->memoryRoot))->toBeFalse()
        ->and(count($provider->requests))->toBe(1)
        ->and($this->project->fresh()->status)->toBe(\App\Enums\ProjectStatus::NeedsYou);
});

it('writes the captured plan on explicit approval, with no second model call for the plan', function () {
    [$service] = architect([
        archPropose(samplePlan(['architecture_md' => '# Arch', 'roadmap_md' => '# Roadmap', 'first_task_md' => '# Task 1', 'summary' => 'We build X.'])),
    ]);

    $service->converse($this->project, 'All agreed.'); // captures the plan
    $service->approvePlan($this->project);             // writes it — no plan model call

    $store = MemoryStore::fromConfig();
    expect($store->read($this->project, 'architecture.md'))->toBe('# Arch')
        ->and($store->read($this->project, 'roadmap.md'))->toBe('# Roadmap')
        ->and($store->read($this->project, 'tasks/T-001/task.md'))->toBe('# Task 1');

    $last = $this->project->consensusMessages()->where('role', MessageRole::System)->orderByDesc('id')->first();
    expect($last->content)->toContain('We build X.')
        ->and($last->meta['planWritten'])->toBeTrue()
        ->and($last->meta['firstTaskId'])->toBe('T-001');
});

/** Mark a plan as already approved so converse() enters the post-plan phase. */
function seedApprovedPlan(Project $project, string $roadmap = "## M1 — Skeleton\n- [ ] T-001 — a\n"): MemoryStore
{
    $store = MemoryStore::fromConfig();
    $store->write($project, 'architecture.md', '# Arch');
    $store->write($project, 'roadmap.md', $roadmap);
    $project->consensusMessages()->create([
        'role' => MessageRole::System, 'content' => 'plan written', 'meta' => ['planWritten' => true],
    ]);

    return $store;
}

it('post-plan a plain reply continues the conversation without forcing a gate', function () {
    // M16-B: the same chat runs after a plan exists — a plain reply is simply the
    // owner's turn, never a forced approve/reject.
    seedApprovedPlan($this->project);

    [$service] = architect([archReply('Sure — tell me what you want to change.')]);
    $result = $service->converse($this->project, 'I might tweak the roadmap');

    expect($result['consensusPending'])->toBeFalse()
        ->and($result['message']->role)->toBe(MessageRole::Architect);
});

it('post-plan a propose_plan revises the existing roadmap through the same consensus gate', function () {
    // M16-B: the two old steering buttons are one chat that can reach a
    // re-proposed, owner-approved plan. A post-plan propose_plan is a REVISION —
    // human-gated like the first, then reconciled (keys preserved).
    $store = seedApprovedPlan($this->project);

    [$service] = architect([
        archPropose(['roadmap_md' => "## M1 — Skeleton\n- [ ] T-001 — a\n- [ ] T-002 — added\n", 'summary' => 'Added T-002.'], 'Here is the revision.'),
        '# fresh brief', // decompose reply while regenerating the restart brief
    ]);

    expect($service->converse($this->project, 'add a task')['consensusPending'])->toBeTrue();

    $service->approvePlan($this->project);

    expect($store->read($this->project, 'roadmap.md'))->toContain('T-002 — added')
        ->and($this->project->events()->where('name', 'plan.redefined')->exists())->toBeTrue();
});

it('post-plan an ask_owner during a redefine parks and writes no plan file', function () {
    // M16-B: a redefine with an open question parks for the owner and changes
    // NO memory until the question is answered.
    $store = seedApprovedPlan($this->project);
    $before = $store->read($this->project, 'roadmap.md');

    [$service] = architect([archAsk([['text' => 'Split M1 how?']], 'One question first.')]);
    $result = $service->converse($this->project, 'restructure M1');

    expect($result['consensusPending'])->toBeFalse()
        ->and($this->project->openQuestions()->count())->toBe(1)
        ->and($store->read($this->project, 'roadmap.md'))->toBe($before); // untouched
});

it('asks the owner to re-propose when there is no captured plan to approve', function () {
    [$service] = architect([]);

    $service->approvePlan($this->project);
    $store = MemoryStore::fromConfig();

    $last = $this->project->consensusMessages()->orderByDesc('id')->first();
    expect($last->content)->toContain('No proposed plan')
        ->and($store->exists($this->project, 'architecture.md'))->toBeFalse();
});

it('salvages a captured plan that has no first task brief', function () {
    [$service] = architect([archPropose(samplePlan(['first_task_md' => '  ']))]);

    $service->converse($this->project, 'go');
    $service->approvePlan($this->project);
    $store = MemoryStore::fromConfig();

    expect($store->read($this->project, 'plan_draft.md'))->not->toBeNull()
        ->and($store->exists($this->project, 'architecture.md'))->toBeFalse()
        ->and($store->exists($this->project, 'tasks/T-001/task.md'))->toBeFalse();
});

it('a plain-text reply (no tool call) is the owner\'s turn, not a crash or stall', function () {
    [$service] = architect([archReply('Let me think about the structure…')]);

    $result = $service->converse($this->project, 'hello');

    expect($result['message']->content)->toBe('Let me think about the structure…')
        ->and($this->project->openQuestions()->count())->toBe(0)
        ->and($result['consensusPending'])->toBeFalse()
        ->and($result)->not->toHaveKey('stalled')
        ->and($this->project->fresh()->status)->toBe(\App\Enums\ProjectStatus::NeedsYou)
        ->and($this->project->events()->where('name', 'consensus.stalled')->count())->toBe(0);
});

it('auto-continues a prose-only turn so an announced-but-unasked batch still reaches the owner', function () {
    // The exact live bug: the Architect narrated "let me ask the next batch" as
    // prose without calling ask_owner, leaving the owner with a promise and no
    // questions. One nudge lets it follow through automatically.
    [$service, $provider] = architect([
        archReply('Good, that gives me a foundation. Let me ask the next batch about the data model.'),
        archAsk([['text' => 'Which storage engine?'], ['text' => 'Normalize prices?']], 'Here they are.'),
    ]);

    $result = $service->converse($this->project, 'go');

    expect($this->project->openQuestions()->count())->toBe(2)
        ->and($this->project->events()->where('name', 'consensus.continued')->count())->toBe(1)
        ->and(count($provider->requests))->toBe(2)
        ->and($result['consensusPending'])->toBeFalse();
});

it('a genuinely conversational turn ends as the owner\'s turn after a single nudge', function () {
    [$service, $provider] = architect([
        archReply('Sure, that works for me.'),
        archReply('Ready when you are.'),
    ]);

    $result = $service->converse($this->project, 'ok');

    expect($result['message']->content)->toBe('Ready when you are.')
        ->and($this->project->openQuestions()->count())->toBe(0)
        ->and(count($provider->requests))->toBe(2) // one nudge, then it terminates — no loop
        ->and($result['consensusPending'])->toBeFalse();
});

it('records answers and feeds them into the next turn history', function () {
    [$service, $provider] = architect([
        archAsk([['text' => 'Tabs or spaces?']], 'Q time.'),
        archReply('Noted.'),
    ]);

    $service->converse($this->project, 'Start');
    $question = $this->project->openQuestions()->first();

    $service->answer($question, 'Spaces');

    expect($question->fresh()->status)->toBe(QuestionStatus::Answered)
        ->and($question->fresh()->answer)->toBe('Spaces');

    $service->converse($this->project); // re-prompt, no new user message

    $history = end($provider->requests)->messages;
    $joined = implode("\n", array_map(fn ($m) => is_string($m['content'] ?? null) ? $m['content'] : '', $history));
    expect($joined)->toContain('Tabs or spaces?')->and($joined)->toContain('Spaces');

    // System prompt no longer lists it as unanswered.
    expect($history[0]['content'])->toContain('no unanswered questions');
});

it('sends the system prompt with open questions listed and offers tools', function () {
    $this->project->questions()->create(['text' => 'Open one?']);

    [$service, $provider] = architect([archReply('ok')]);
    $service->converse($this->project, 'hi');

    expect($provider->requests[0]->messages[0]['content'])->toContain('Open one?')
        ->and($provider->requests[0]->tools)->not->toBeNull()
        ->and($provider->requests[0]->model)->toBe(config('majordom.architect.model'));
});

it('flips project status with the question gate', function () {
    [$service] = architect([
        archAsk([['text' => 'One?']]),
        archReply('ok'),
    ]);

    $service->converse($this->project, 'Start');
    expect($this->project->fresh()->status)->toBe(\App\Enums\ProjectStatus::NeedsYou);

    $service->answer($this->project->openQuestions()->first(), 'Yes');
    expect($this->project->fresh()->status)->toBe(\App\Enums\ProjectStatus::Working);

    // A reply-only follow-up ends as NeedsYou (the owner's turn) — never a silent
    // Idle, and no stall to recover (M15: the state is always known).
    $this->project->refresh();
    $service->converse($this->project);
    expect($this->project->fresh()->status)->toBe(\App\Enums\ProjectStatus::NeedsYou);
});

it('notes a greenfield (empty) repository in the consensus system prompt', function () {
    [$service, $provider] = architect([archReply('ok')]);

    // Factory repo_path is not a real git repo → RepoIndex yields null → greenfield note.
    $service->converse($this->project, 'hi');

    expect($provider->requests[0]->messages[0]['content'])->toContain('greenfield');
});
