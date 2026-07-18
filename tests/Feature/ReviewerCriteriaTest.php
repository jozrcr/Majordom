<?php

use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Agents\Reviewer\ReviewerService;
use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(fn () => setupMemoryRoot());

function bindCriteriaReviewer(): object
{
    $fake = new class implements Provider {
        public ?ProviderRequest $last = null;

        public function chat(ProviderRequest $request): ProviderResponse
        {
            $this->last = $request;

            return new ProviderResponse('{"verdict":"approved","comments":[],"summary":"ok"}', 'stop', 5, 5);
        }
    };
    app()->instance(Provider::class, $fake);

    return $fake;
}

function userMessageOf(object $reviewer): string
{
    return collect($reviewer->last->messages)->firstWhere('role', 'user')['content'] ?? '';
}

it('judges the ORIGINAL criteria, not the reviewer\'s own accumulated comments', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id, 'task_key' => 'T-012', 'revision' => 3, 'status' => TaskStatus::Reviewing]);

    $memory = app(MemoryStore::class);
    $memory->write($project, 'tasks/T-012/task.md', "# T-012\n## Acceptance criteria\n- CRITERION_MARKER: wrapper works without a surface argument.");
    // The revision brief folds in the reviewer's OWN escalating remark:
    $memory->write($project, 'tasks/T-012/task.v3.md',
        "# T-012\n## Acceptance criteria\n- CRITERION_MARKER: wrapper works without a surface argument.\n\n## Review comments (revision 3)\n\nINVENTED_REQUIREMENT: the wrapper must also fully support the surface parameter.");

    $reviewer = bindCriteriaReviewer();
    app(ReviewerService::class)->review($task, '+some diff', true);

    $sent = userMessageOf($reviewer);
    expect($sent)->toContain('CRITERION_MARKER')
        ->and($sent)->not->toContain('INVENTED_REQUIREMENT'); // reviewer's own comments are not fed back as spec
});

it('still honors owner clarifications as binding criteria', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id, 'task_key' => 'T-012', 'revision' => 4, 'status' => TaskStatus::Reviewing]);

    $memory = app(MemoryStore::class);
    $memory->write($project, 'tasks/T-012/task.md', "# T-012\n## Acceptance criteria\n- base criterion.");
    $memory->write($project, 'tasks/T-012/task.v4.md',
        "# T-012\n## Acceptance criteria\n- base criterion.\n\n## Owner clarifications (revision 4)\n\nCLARIFICATION_MARKER: the join code is case-insensitive.");

    $reviewer = bindCriteriaReviewer();
    app(ReviewerService::class)->review($task, '+diff', true);

    expect(userMessageOf($reviewer))->toContain('CLARIFICATION_MARKER');
});
