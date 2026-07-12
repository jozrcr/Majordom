<?php

use App\Enums\ProjectStatus;
use App\Livewire\ProjectDashboard;
use App\Models\Project;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    config(['majordom.token' => 'secret']);
});

test('GET / shows project name and repo_path when authenticated', function () {
    Project::create([
        'name' => 'Test Project',
        'slug' => 'test-project',
        'repo_path' => '/tmp/test-repo',
        'status' => ProjectStatus::Idle,
        'last_activity_at' => now(),
    ]);

    $this->withHeader('Authorization', 'Bearer secret')
        ->get('/')
        ->assertSee('Test Project')
        ->assertSee('/tmp/test-repo');
});

test('GET / shows No projects yet when authenticated and empty', function () {
    $this->withHeader('Authorization', 'Bearer secret')
        ->get('/')
        ->assertSee('No projects yet');
});

test('createProject creates a project with valid git repo', function () {
    $dir = sys_get_temp_dir() . '/mjd-test-' . uniqid();
    mkdir($dir . '/.git', 0777, true);

    Livewire::test(ProjectDashboard::class)
        ->set('name', 'New Repo')
        ->set('repoPath', $dir)
        ->call('createProject')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    expect(Project::where('slug', 'new-repo')->first())
        ->not->toBeNull()
        ->status->toBe(ProjectStatus::Idle);

    array_map('unlink', glob("$dir/.git/*"));
    rmdir("$dir/.git");
    rmdir($dir);
});

test('createProject fails with non-git path', function () {
    $dir = sys_get_temp_dir() . '/mjd-test-' . uniqid();
    mkdir($dir, 0777, true);

    Livewire::test(ProjectDashboard::class)
        ->set('name', 'Bad Repo')
        ->set('repoPath', $dir)
        ->call('createProject')
        ->assertHasErrors(['repoPath' => 'Not a git repository.']);

    expect(Project::where('name', 'Bad Repo')->count())->toBe(0);

    rmdir($dir);
});

test('createProject fails with duplicate name', function () {
    Project::create([
        'name' => 'Existing',
        'slug' => 'existing',
        'repo_path' => '/tmp/existing',
        'status' => ProjectStatus::Idle,
        'last_activity_at' => now(),
    ]);

    $dir = sys_get_temp_dir() . '/mjd-test-' . uniqid();
    mkdir($dir . '/.git', 0777, true);

    Livewire::test(ProjectDashboard::class)
        ->set('name', 'Existing')
        ->set('repoPath', $dir)
        ->call('createProject')
        ->assertHasErrors(['name' => 'A project with this name already exists.']);

    expect(Project::where('name', 'Existing')->count())->toBe(1);

    array_map('unlink', glob("$dir/.git/*"));
    rmdir("$dir/.git");
    rmdir($dir);
});

test('unauthenticated GET / redirects to login', function () {
    $this->get('/')
        ->assertRedirect('/login');
});

test('archived projects hide from the dashboard behind the toggle', function () {
    config(['majordom.token' => 'secret']);
    $active = \App\Models\Project::factory()->create(['name' => 'Active One']);
    $archived = \App\Models\Project::factory()->create(['name' => 'Old One', 'archived_at' => now()]);

    \Livewire\Livewire::test(\App\Livewire\ProjectDashboard::class)
        ->assertSee('Active One')
        ->assertDontSee('Old One')
        ->assertSee('archived (1)')
        ->set('showArchived', true)
        ->assertSee('Old One')
        ->assertDontSee('Active One');
});

test('workspace archive toggle sets and clears archived_at', function () {
    $project = \App\Models\Project::factory()->create();

    \Livewire\Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project])
        ->call('toggleArchive');

    expect($project->fresh()->archived_at)->not->toBeNull();

    \Livewire\Livewire::test(\App\Livewire\ProjectWorkspace::class, ['project' => $project->fresh()])
        ->call('toggleArchive');

    expect($project->fresh()->archived_at)->toBeNull();
});
