<?php

use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRegistry;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Models\Project;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\RepoIndex;
use Illuminate\Support\Facades\Process;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

class InspectScriptedProvider implements Provider
{
    public int $calls = 0;

    public array $requests = [];

    public array $lastMessages = [];

    public function __construct(public array $responses) {}

    public function chat(ProviderRequest $request): ProviderResponse
    {
        $this->calls++;
        $this->requests[] = $request;
        $this->lastMessages = $request->messages;
        $next = array_shift($this->responses);

        if ($next instanceof ProviderResponse) {
            return $next;
        }

        return new ProviderResponse($next ?? '', 'stop', 5, 5);
    }
}

function inspectService(array $responses): array
{
    $provider = new InspectScriptedProvider($responses);
    app()->instance(Provider::class, $provider);

    return [
        new ArchitectService(app(ProviderRegistry::class), MemoryStore::fromConfig(), app(RepoIndex::class)),
        $provider,
    ];
}

/** Concatenate the tool-result messages fed back on the loop's last request. */
function toolResults(InspectScriptedProvider $provider): string
{
    $tools = array_filter($provider->lastMessages, fn ($m) => ($m['role'] ?? '') === 'tool');

    return implode("\n", array_map(fn ($m) => (string) ($m['content'] ?? ''), $tools));
}

beforeEach(function () {
    $this->repoDir = sys_get_temp_dir().'/majordom-inspect-'.uniqid();
    mkdir($this->repoDir.'/app', 0777, true);
    file_put_contents($this->repoDir.'/app/Foo.php', "<?php\nclass Foo { public function bar() {} }\n");
    file_put_contents($this->repoDir.'/.env', "SECRET_KEY=supersecret\n"); // gitignored, untracked
    config(['majordom.memory_root' => sys_get_temp_dir().'/majordom-inspect-mem-'.uniqid()]);
    $this->project = Project::factory()->create(['repo_path' => $this->repoDir]);
});

afterEach(function () {
    if (isset($this->repoDir) && is_dir($this->repoDir)) {
        exec('rm -rf '.escapeshellarg($this->repoDir));
    }
});

it('fulfils a read_file call and feeds the contents back, then continues to a question', function () {
    Process::fake(['*' => Process::result("app/Foo.php\n")]); // ls-tree: only app/Foo.php is tracked

    [$service, $provider] = inspectService([
        archReadFile('app/Foo.php'),
        archAsk([['text' => 'Keep Foo::bar?']], 'Saw it.'),
    ]);

    $service->converse($this->project, 'Start');

    expect(toolResults($provider))->toContain('class Foo')
        ->and($this->project->events()->where('name', 'consensus.inspected')->count())->toBe(1)
        ->and($provider->calls)->toBe(2)
        ->and($this->project->openQuestions()->pluck('text')->all())->toContain('Keep Foo::bar?');
});

it('withdraws read tools after the read-round budget so the loop must conclude', function () {
    Process::fake(['*' => Process::result("app/Foo.php\n")]);

    [$service, $provider] = inspectService([
        archReadFile('app/Foo.php'),
        archReadFile('app/Foo.php'),
        archReadFile('app/Foo.php'),
        archReadFile('app/Foo.php'),
        archReply('I have enough context now.'),
    ]);

    $result = $service->converse($this->project, 'Start');

    // 4 read rounds + 1 concluding turn where reads were no longer offered.
    $lastTools = array_map(fn ($t) => $t->name, end($provider->requests)->tools);

    expect($provider->calls)->toBe(5)
        ->and($lastTools)->not->toContain('read_file')
        ->and($lastTools)->toContain('propose_plan')
        ->and($result['message']->content)->toContain('enough context');
});

it('refuses to read an untracked path so secrets never leak', function () {
    Process::fake(['*' => Process::result("app/Foo.php\n")]); // .env is NOT in the tracked set

    [$service, $provider] = inspectService([
        archReadFile('.env'),
        archAsk([['text' => 'next?']], 'ok'),
    ]);

    $service->converse($this->project, 'Start');

    expect(toolResults($provider))->toContain('not a tracked file')
        ->and(toolResults($provider))->not->toContain('supersecret');
});

it('confines readFile to inside the repo', function () {
    $repo = app(RepoIndex::class);

    expect($repo->readFile($this->repoDir, 'app/Foo.php'))->toContain('class Foo')
        ->and($repo->readFile($this->repoDir, '../../../../etc/passwd'))->toBeNull()
        ->and($repo->readFile($this->repoDir, 'nope/missing.php'))->toBeNull();
});

it('tells the Architect it reads files via tools and must never ask to paste', function () {
    // BUG 1 (mansarde) regression guard, M15 form: the read affordance is now a
    // real tool, so the prompt names it and forbids the paste ask.
    Process::fake(['*' => Process::result("app/Foo.php\n")]);

    [$service, $provider] = inspectService([
        archAsk([['text' => 'q?']], 'ok'),
    ]);

    $service->converse($this->project, 'Start');

    $system = $provider->lastMessages[0]['content'] ?? '';

    expect($provider->lastMessages[0]['role'] ?? null)->toBe('system')
        ->and($system)->toContain('read_file')
        ->and($system)->toContain('paste');
});
