<?php

use App\Agents\Architect\ArchitectEnvelope;
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

    public function __construct(public array $responses) {}

    public function chat(ProviderRequest $request): ProviderResponse
    {
        $this->calls++;
        $content = array_shift($this->responses) ?? '{"reply":"…","questions":[],"consensus_reached":false}';

        return new ProviderResponse($content, 'stop', 5, 5);
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

it('fetches a requested tracked file and continues to a question', function () {
    Process::fake(['*' => Process::result("app/Foo.php\n")]); // ls-tree: only app/Foo.php is tracked

    [$service, $provider] = inspectService([
        json_encode(['reply' => '', 'questions' => [], 'consensus_reached' => false, 'reads' => ['app/Foo.php']]),
        json_encode(['reply' => 'Saw it.', 'questions' => [['text' => 'Keep Foo::bar?']], 'consensus_reached' => false]),
    ]);

    $service->converse($this->project, 'Start');

    $inspection = $this->project->consensusMessages()->get()
        ->first(fn ($m) => ($m->meta['inspection'] ?? false) === true);

    expect($inspection)->not->toBeNull()
        ->and($inspection->content)->toContain('class Foo')
        ->and($this->project->events()->where('name', 'consensus.inspected')->count())->toBe(1)
        ->and($provider->calls)->toBe(2)
        ->and($this->project->openQuestions()->pluck('text')->all())->toContain('Keep Foo::bar?');
});

it('stops after the inspection-round cap and surfaces a recoverable stall', function () {
    Process::fake(['*' => Process::result("app/Foo.php\n")]);

    $read = json_encode(['reply' => '', 'questions' => [], 'consensus_reached' => false, 'reads' => ['app/Foo.php']]);
    [$service, $provider] = inspectService([$read, $read, $read, $read]);

    $result = $service->converse($this->project, 'Start');

    expect($this->project->consensusMessages()->get()->filter(fn ($m) => $m->meta['inspection'] ?? false)->count())->toBe(2)
        ->and($provider->calls)->toBe(3) // 2 inspection rounds + 1 final
        ->and($result['stalled'])->toBeTrue()
        ->and($this->project->events()->where('name', 'consensus.stalled')->count())->toBe(1);
});

it('refuses to read an untracked path so secrets never leak', function () {
    Process::fake(['*' => Process::result("app/Foo.php\n")]); // .env is NOT in the tracked set

    [$service] = inspectService([
        json_encode(['reply' => '', 'questions' => [], 'consensus_reached' => false, 'reads' => ['.env']]),
        json_encode(['reply' => 'ok', 'questions' => [['text' => 'next?']], 'consensus_reached' => false]),
    ]);

    $service->converse($this->project, 'Start');

    $inspection = $this->project->consensusMessages()->get()
        ->first(fn ($m) => ($m->meta['inspection'] ?? false) === true);

    expect($inspection)->not->toBeNull()
        ->and($inspection->content)->toContain('not a tracked file')
        ->and($inspection->content)->not->toContain('supersecret');
});

it('confines readFile to inside the repo', function () {
    $repo = app(RepoIndex::class);

    expect($repo->readFile($this->repoDir, 'app/Foo.php'))->toContain('class Foo')
        ->and($repo->readFile($this->repoDir, '../../../../etc/passwd'))->toBeNull()
        ->and($repo->readFile($this->repoDir, 'nope/missing.php'))->toBeNull();
});

it('parses and normalizes reads from the envelope', function () {
    $env = ArchitectEnvelope::fromContent(json_encode([
        'reply' => 'x', 'questions' => [], 'consensus_reached' => false,
        'reads' => ['a.php', ' b/ ', 3, ''],
    ]));

    expect($env->reads)->toBe(['a.php', 'b/']);
});
