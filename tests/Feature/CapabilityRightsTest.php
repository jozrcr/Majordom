<?php

use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRegistry;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Enums\CapabilityLevel;
use App\Livewire\ProjectWorkspace;
use App\Models\Project;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\RepoIndex;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

class CapScriptedProvider implements Provider
{
    public int $calls = 0;

    public array $lastMessages = [];

    public array $requests = [];

    public function __construct(public array $responses) {}

    public function chat(ProviderRequest $request): ProviderResponse
    {
        $this->calls++;
        $this->lastMessages = $request->messages;
        $this->requests[] = $request;
        $next = array_shift($this->responses);

        if ($next instanceof ProviderResponse) {
            return $next;
        }

        return new ProviderResponse($next ?? 'x', 'stop', 5, 5);
    }
}

function capService(array $responses): array
{
    $provider = new CapScriptedProvider($responses);
    app()->instance(Provider::class, $provider);

    return [new ArchitectService(app(ProviderRegistry::class), MemoryStore::fromConfig(), app(RepoIndex::class)), $provider];
}

beforeEach(function () {
    $this->repoDir = sys_get_temp_dir().'/majordom-cap-'.uniqid();
    mkdir($this->repoDir.'/app', 0777, true);
    file_put_contents($this->repoDir.'/app/Foo.php', "<?php\nclass Foo {}\n");
    config(['majordom.memory_root' => sys_get_temp_dir().'/majordom-cap-mem-'.uniqid()]);
    $this->project = Project::factory()->create(['repo_path' => $this->repoDir]);
});

afterEach(function () {
    if (isset($this->repoDir) && is_dir($this->repoDir)) {
        exec('rm -rf '.escapeshellarg($this->repoDir));
    }
});

it('enum: read/commands can read, only commands runs commands, commands not selectable', function () {
    expect(CapabilityLevel::None->canRead())->toBeFalse()
        ->and(CapabilityLevel::Read->canRead())->toBeTrue()
        ->and(CapabilityLevel::Commands->canRead())->toBeTrue()
        ->and(CapabilityLevel::Read->canRunCommands())->toBeFalse()
        ->and(CapabilityLevel::Commands->canRunCommands())->toBeTrue()
        ->and(CapabilityLevel::Commands->selectable())->toBeFalse()
        ->and(CapabilityLevel::Read->selectable())->toBeTrue()
        ->and(CapabilityLevel::fromValue(null))->toBe(CapabilityLevel::Read);
});

it('a project defaults to Read capability', function () {
    expect($this->project->capability())->toBe(CapabilityLevel::Read);
});

it('grants reads by default: an inspection turn is fulfilled', function () {
    Process::fake(['*' => Process::result("app/Foo.php\n")]);

    [$service, $provider] = capService([
        archReadFile('app/Foo.php'),
        archAsk([['text' => 'q?']], 'saw it'),
    ]);

    $service->converse($this->project, 'go');

    expect($provider->calls)->toBe(2) // read round fired, then the real turn
        ->and($this->project->events()->where('name', 'consensus.inspected')->count())->toBe(1)
        ->and($provider->lastMessages[0]['content'])->toContain('read_file');
});

it('withholds reads when capability is None: no read tools are offered and the Architect is told to ask', function () {
    Process::fake(['*' => Process::result("app/Foo.php\n")]);
    $this->project->update(['capability_level' => CapabilityLevel::None]);

    [$service, $provider] = capService([
        archAsk([['text' => 'share Foo.php?']], 'I would look but cannot'),
    ]);

    $service->converse($this->project, 'go');

    $toolNames = array_map(fn ($t) => $t->name, $provider->requests[0]->tools ?? []);
    expect($provider->calls)->toBe(1) // no read loop
        ->and($this->project->events()->where('name', 'consensus.inspected')->count())->toBe(0)
        ->and($toolNames)->not->toContain('read_file')
        ->and($provider->lastMessages[0]['content'])->toContain('do NOT have read access');
});

it('Settings: owner can set Read/None but the gated Commands tier is refused server-side', function () {
    Livewire::test(ProjectWorkspace::class, ['project' => $this->project])
        ->call('setCapabilityLevel', 'none')
        ->assertOk();
    expect($this->project->fresh()->capability())->toBe(CapabilityLevel::None);

    Livewire::test(ProjectWorkspace::class, ['project' => $this->project->fresh()])
        ->call('setCapabilityLevel', 'commands'); // gated — must not apply
    expect($this->project->fresh()->capability())->toBe(CapabilityLevel::None);

    Livewire::test(ProjectWorkspace::class, ['project' => $this->project->fresh()])
        ->call('setCapabilityLevel', 'read');
    expect($this->project->fresh()->capability())->toBe(CapabilityLevel::Read);
});
