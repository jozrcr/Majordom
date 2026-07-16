<?php

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Livewire\ProjectWorkspace;
use App\Models\Approval;
use App\Models\Project;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

function reviewApproval(Project $project, array $comments): Approval
{
    return Approval::create([
        'project_id' => $project->id,
        'type' => ApprovalType::Review,
        'status' => ApprovalStatus::Open,
        'title' => 'Review requested',
        'payload' => [
            'testsPassed' => true,
            'filesChanged' => ['a.php'],
            'diff' => "diff --git a/a.php b/a.php\n+x",
            'verdict' => ['summary' => 'ok', 'comments' => $comments],
        ],
    ]);
}

test('review card renders structured {file, comment} reviewer comments', function () {
    $project = Project::factory()->create();
    reviewApproval($project, [
        ['file' => 'schemas/x.gschema.xml', 'comment' => 'Verify it compiles with glib-compile-schemas.'],
    ]);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('schemas/x.gschema.xml')
        ->assertSee('Verify it compiles');
});

test('review card still renders plain-string reviewer comments', function () {
    $project = Project::factory()->create();
    reviewApproval($project, ['Rename the flag to --log-file']);

    Livewire::test(ProjectWorkspace::class, ['project' => $project])
        ->assertSee('Rename the flag to --log-file');
});
