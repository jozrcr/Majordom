<?php

use App\Agents\Architect\ArchitectService;
use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRegistry;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Models\Project;
use App\Projects\Memory\MemoryStore;
use App\Projects\Repositories\RepoIndex;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

class BootstrapScriptedProvider implements Provider
{
    public int $calls = 0;

    public function __construct(public array $responses) {}

    public function chat(ProviderRequest $request): ProviderResponse
    {
        $this->calls++;
        $next = array_shift($this->responses);

        if ($next instanceof ProviderResponse) {
            return $next;
        }

        return new ProviderResponse($next ?? '{}', 'stop', 3, 3);
    }
}

function bootstrapService(array $responses): array
{
    $p = new BootstrapScriptedProvider($responses);
    app()->instance(Provider::class, $p);

    return [new ArchitectService(app(ProviderRegistry::class), MemoryStore::fromConfig(), app(RepoIndex::class)), $p];
}

beforeEach(function () {
    config([
        'majordom.memory_root' => sys_get_temp_dir().'/mj-bootstrap-mem-'.uniqid(),
        'majordom.git.author_name' => 'Majordom Test',
        'majordom.git.author_email' => 'test@example.com',
    ]);
    $this->repoDir = sys_get_temp_dir().'/mj-bootstrap-'.uniqid();
    mkdir($this->repoDir, 0777, true);
    exec('git -C '.escapeshellarg($this->repoDir).' init -q'); // empty repo, unborn HEAD = greenfield
    $this->project = Project::factory()->create(['repo_path' => $this->repoDir]);
});

afterEach(function () {
    if (isset($this->repoDir) && is_dir($this->repoDir)) {
        exec('rm -rf '.escapeshellarg($this->repoDir));
    }
});

// Review verdict fixtures (Builder Selection: the frontier Builder's scaffold
// goes through the Reviewer before commit — M14b T-67 reconciliation).
const REVIEW_OK = '{"verdict":"approved","comments":[],"summary":"sound scaffold"}';
const REVIEW_CHANGES = '{"verdict":"changes_requested","comments":[{"comment":"composer.json is malformed"}],"summary":"fix the manifest"}';

it('scaffolds a greenfield repo, reviews it, then commits', function () {
    [$service, $provider] = bootstrapService([
        json_encode(['files' => [
            ['path' => 'README.md', 'contents' => "# Project\n"],
            ['path' => 'src/main.py', 'contents' => "print('hi')\n"],
        ], 'commit_message' => 'chore: scaffold']),
        REVIEW_OK,
    ]);

    $ok = $service->bootstrapRepo($this->project);

    expect($ok)->toBeTrue()
        ->and(is_file($this->repoDir.'/README.md'))->toBeTrue()
        ->and(is_file($this->repoDir.'/src/main.py'))->toBeTrue()
        ->and($provider->calls)->toBe(2) // frontier Builder generates + Reviewer validates
        ->and($this->project->events()->where('name', 'repo.bootstrapped')->count())->toBe(1)
        ->and($this->project->events()->where('name', 'build.builder_selected')->get()
            ->contains(fn ($e) => ($e->payload['phase'] ?? null) === 'bootstrap'))->toBeTrue()
        ->and(app(RepoIndex::class)->fileList($this->repoDir))->toContain('README.md'); // committed
});

it('retries once when the scaffold is rejected, then commits the fixed one', function () {
    [$service, $provider] = bootstrapService([
        json_encode(['files' => [['path' => 'composer.json', 'contents' => 'BROKEN']], 'commit_message' => 'x']),
        REVIEW_CHANGES,
        json_encode(['files' => [['path' => 'composer.json', 'contents' => "{}\n"], ['path' => 'README.md', 'contents' => "# ok\n"]], 'commit_message' => 'chore: scaffold']),
        REVIEW_OK,
    ]);

    $ok = $service->bootstrapRepo($this->project);

    expect($ok)->toBeTrue()
        ->and($provider->calls)->toBe(4) // gen, review(changes), gen, review(ok)
        ->and(is_file($this->repoDir.'/README.md'))->toBeTrue()
        ->and($this->project->events()->where('name', 'repo.bootstrapped')->count())->toBe(1);
});

it('surfaces (does not commit) a scaffold still rejected after the retry', function () {
    [$service, $provider] = bootstrapService([
        json_encode(['files' => [['path' => 'composer.json', 'contents' => 'BROKEN']], 'commit_message' => 'x']),
        REVIEW_CHANGES,
        json_encode(['files' => [['path' => 'composer.json', 'contents' => 'STILL BROKEN']], 'commit_message' => 'x']),
        REVIEW_CHANGES,
    ]);

    $ok = $service->bootstrapRepo($this->project);

    expect($ok)->toBeFalse()
        ->and(app(RepoIndex::class)->fileList($this->repoDir))->toBeNull() // nothing committed — still greenfield
        ->and($this->project->events()->where('name', 'repo.bootstrap_review_rejected')->count())->toBe(1)
        ->and($this->project->consensusMessages()->get()
            ->contains(fn ($m) => ($m->meta['bootstrap_rejected'] ?? false) === true))->toBeTrue();
});

it('no-ops on a non-greenfield repo without calling the model', function () {
    file_put_contents($this->repoDir.'/existing.txt', "x\n");
    exec('cd '.escapeshellarg($this->repoDir).' && git add -A && git -c user.email=t@t -c user.name=t commit -qm init');

    [$service, $provider] = bootstrapService(['{"files":[]}']);

    expect($service->bootstrapRepo($this->project))->toBeFalse()
        ->and($provider->calls)->toBe(0);
});

it('rejects scaffold paths that escape the repo', function () {
    [$service] = bootstrapService([
        json_encode(['files' => [
            ['path' => 'ok.txt', 'contents' => 'ok'],
            ['path' => '../evil.txt', 'contents' => 'nope'],
        ]]),
        REVIEW_OK,
    ]);

    $service->bootstrapRepo($this->project);

    expect(is_file($this->repoDir.'/ok.txt'))->toBeTrue()
        ->and(is_file(dirname($this->repoDir).'/evil.txt'))->toBeFalse();
});

it('approvePlan scaffolds (through review) when the repo is greenfield', function () {
    [$service] = bootstrapService([
        archPropose(samplePlan(['first_task_md' => '# Task'])), // captured by converse
        json_encode(['files' => [['path' => 'README.md', 'contents' => "# x\n"]], 'commit_message' => 'chore: scaffold']),
        REVIEW_OK,
    ]);

    $service->converse($this->project, 'go'); // capture the plan
    $service->approvePlan($this->project);     // write it + scaffold through review

    expect(is_file($this->repoDir.'/README.md'))->toBeTrue()
        ->and($this->project->events()->where('name', 'repo.bootstrapped')->count())->toBe(1);
});
