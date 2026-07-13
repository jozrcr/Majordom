<?php

use App\Agents\Architect\ArchitectEnvelope;
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

    /** @param string[] $responses */
    public function __construct(public array $responses) {}

    public function chat(ProviderRequest $request): ProviderResponse
    {
        $this->requests[] = $request;
        $content = array_shift($this->responses) ?? '{"reply":"…","questions":[],"consensus_reached":false}';

        return new ProviderResponse($content, 'stop', 10, 20);
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

    return [new ArchitectService(app(\App\Agents\Providers\ProviderRegistry::class), MemoryStore::fromConfig()), $provider];
}

it('persists user + architect messages and creates questions', function () {
    [$service] = architect([json_encode([
        'reply' => 'I need to know two things first.',
        'questions' => [
            ['text' => 'Which auth method?', 'options' => ['token', 'oauth']],
            ['text' => 'Is SQLite acceptable?'],
        ],
        'consensus_reached' => false,
    ])]);

    $result = $service->converse($this->project, 'Build me a login page');

    expect($result['consensusPending'])->toBeFalse()
        ->and($this->project->consensusMessages()->count())->toBe(2)
        ->and($this->project->openQuestions()->count())->toBe(2);

    $q = $this->project->questions()->first();
    expect($q->options)->toBe(['token', 'oauth'])
        ->and($q->consensus_message_id)->toBe($result['message']->id);
});

it('ignores a consensus claim while questions from the same turn are open', function () {
    [$service] = architect([json_encode([
        'reply' => 'Consensus! But also…',
        'questions' => [['text' => 'One more thing?']],
        'consensus_reached' => true,
    ])]);

    $result = $service->converse($this->project, 'Go');

    expect($result['consensusPending'])->toBeFalse()
        ->and(is_dir($this->memoryRoot))->toBeFalse();
});

it('ignores a consensus claim while previously raised questions are open', function () {
    $this->project->questions()->create(['text' => 'Still open?']);

    [$service] = architect([json_encode([
        'reply' => 'We are done, right?',
        'questions' => [],
        'consensus_reached' => true,
    ])]);

    expect($service->converse($this->project, 'Proceed')['consensusPending'])->toBeFalse();
});

it('reports consensus pending and does NOT write the plan without approval', function () {
    [$service, $provider] = architect([
        json_encode(['reply' => 'Agreed scope: X.', 'questions' => [], 'consensus_reached' => true]),
    ]);

    $result = $service->converse($this->project, 'All agreed.');

    expect($result['consensusPending'])->toBeTrue()
        ->and(is_dir($this->memoryRoot))->toBeFalse()
        ->and(count($provider->requests))->toBe(1)
        ->and($this->project->fresh()->status)->toBe(\App\Enums\ProjectStatus::NeedsYou);
});

it('writes the plan on explicit approval', function () {
    [$service, $provider] = architect([
        json_encode([
            'architecture_md' => '# Arch',
            'roadmap_md' => '# Roadmap',
            'first_task_id' => 'T-001',
            'first_task_md' => '# Task 1',
            'summary' => 'We build X.',
        ]),
    ]);

    $service->approvePlan($this->project);
    $store = MemoryStore::fromConfig();

    expect($store->read($this->project, 'architecture.md'))->toBe('# Arch')
        ->and($store->read($this->project, 'roadmap.md'))->toBe('# Roadmap')
        ->and($store->read($this->project, 'tasks/T-001/task.md'))->toBe('# Task 1')
        ->and($provider->requests[0]->jsonMode)->toBeTrue();

    $last = $this->project->consensusMessages()->orderByDesc('id')->first();
    expect($last->role)->toBe(MessageRole::System)
        ->and($last->content)->toContain('We build X.');
});

it('salvages a malformed plan response into plan_draft.md', function () {
    [$service] = architect(['this is not json at all']);

    $service->approvePlan($this->project);
    $store = MemoryStore::fromConfig();

    expect($store->read($this->project, 'plan_draft.md'))->toBe('this is not json at all')
        ->and($store->exists($this->project, 'architecture.md'))->toBeFalse();
});

it('degrades a malformed envelope to a plain reply without crashing', function () {
    [$service] = architect(['plain text, no JSON here']);

    $result = $service->converse($this->project, 'hello');

    expect($result['message']->content)->toBe('plain text, no JSON here')
        ->and($this->project->openQuestions()->count())->toBe(0)
        ->and($result['consensusPending'])->toBeFalse();
});

it('parses an envelope wrapped in markdown fences', function () {
    $env = ArchitectEnvelope::fromContent("```json\n{\"reply\":\"hi\",\"questions\":[],\"consensus_reached\":false}\n```");

    expect($env->reply)->toBe('hi')->and($env->consensusReached)->toBeFalse();
});

it('records answers and feeds them into the next turn history', function () {
    [$service, $provider] = architect([
        json_encode(['reply' => 'Q time.', 'questions' => [['text' => 'Tabs or spaces?']], 'consensus_reached' => false]),
        json_encode(['reply' => 'Noted.', 'questions' => [], 'consensus_reached' => false]),
    ]);

    $service->converse($this->project, 'Start');
    $question = $this->project->openQuestions()->first();

    $service->answer($question, 'Spaces');

    expect($question->fresh()->status)->toBe(QuestionStatus::Answered)
        ->and($question->fresh()->answer)->toBe('Spaces');

    $service->converse($this->project); // re-prompt, no new user message

    $history = end($provider->requests)->messages;
    $joined = implode("\n", array_column($history, 'content'));
    expect($joined)->toContain('Tabs or spaces?')->and($joined)->toContain('Spaces');

    // System prompt no longer lists it as unanswered.
    expect($history[0]['content'])->toContain('no unanswered questions');
});

it('sends the system prompt with open questions listed', function () {
    $this->project->questions()->create(['text' => 'Open one?']);

    [$service, $provider] = architect([json_encode(['reply' => 'ok', 'questions' => [], 'consensus_reached' => false])]);
    $service->converse($this->project, 'hi');

    expect($provider->requests[0]->messages[0]['content'])->toContain('Open one?')
        ->and($provider->requests[0]->jsonMode)->toBeTrue()
        ->and($provider->requests[0]->model)->toBe(config('majordom.architect.model'));
});

it('flips project status with the question gate', function () {
    [$service] = architect([
        json_encode(['reply' => 'Q.', 'questions' => [['text' => 'One?']], 'consensus_reached' => false]),
        json_encode(['reply' => 'ok', 'questions' => [], 'consensus_reached' => false]),
    ]);

    $service->converse($this->project, 'Start');
    expect($this->project->fresh()->status)->toBe(\App\Enums\ProjectStatus::NeedsYou);

    $service->answer($this->project->openQuestions()->first(), 'Yes');
    expect($this->project->fresh()->status)->toBe(\App\Enums\ProjectStatus::Working);

    $service->converse($this->project);
    expect($this->project->fresh()->status)->toBe(\App\Enums\ProjectStatus::Idle);
});

it('refuses to write an empty first task brief', function () {
    [$service] = architect([
        json_encode(['architecture_md' => '# A', 'roadmap_md' => '# R', 'first_task_id' => 'T-001', 'first_task_md' => '  ', 'summary' => 's']),
    ]);

    $service->approvePlan($this->project);
    $store = MemoryStore::fromConfig();

    expect($store->exists($this->project, 'tasks/T-001/task.md'))->toBeFalse()
        ->and($store->read($this->project, 'plan_draft.md'))->not->toBeNull();
});
