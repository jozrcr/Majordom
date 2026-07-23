<?php

use App\Agents\Providers\Provider;
use App\Agents\Providers\ProviderRequest;
use App\Agents\Providers\ProviderResponse;
use App\Core\Workflow\Nodes\ReviewNode;
use App\Enums\NodeStatus;
use App\Models\Execution;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Fake reviewer Provider that captures the request and approves. */
function bindCapturingReviewer(): object
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

it('reviews the cumulative task diff (base_commit..worktree), not the last incremental change', function () {
    setupMemoryRoot();
    $repo = sys_get_temp_dir().'/mj-review-'.uniqid();
    mkdir($repo, 0777, true);
    $git = fn (string $args) => exec('cd '.escapeshellarg($repo)." && git -c user.email=t@t -c user.name=t {$args} 2>&1");

    $git('init -q');
    file_put_contents($repo.'/README.md', "start\n");
    $git('add -A');
    $git('commit -qm base');
    $base = trim(shell_exec('cd '.escapeshellarg($repo).' && git rev-parse HEAD'));

    // Prior revisions built the real feature (this is the task's cumulative work).
    file_put_contents($repo.'/GroupScreen.kt', "class GroupScreenCumulativeMarker {}\n");
    $git('add -A');
    $git('commit -qm "feat: group screens"');

    $project = Project::factory()->create(['repo_path' => $repo]);
    $execution = Execution::factory()->create(['project_id' => $project->id]);
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'task_key' => 'T-014',
        'worktree_path' => $repo,
        'base_commit' => $base,
        'status' => \App\Enums\TaskStatus::Reviewing,
    ]);
    $execution->tasks()->save($task);

    // The build node's INCREMENTAL diff is a tiny, unrelated change.
    Node::factory()->create([
        'execution_id' => $execution->id,
        'type' => 'build',
        'status' => NodeStatus::Completed,
        'output' => ['diff' => '+// just a tiny incremental import', 'filesChanged' => ['GroupScreen.kt']],
    ]);
    $reviewNode = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'review', 'status' => NodeStatus::Pending]);

    $reviewer = bindCapturingReviewer();

    (new ReviewNode($reviewNode->id))->handle();

    $sentToReviewer = collect($reviewer->last->messages)->firstWhere('role', 'user')['content'] ?? '';

    // The reviewer saw the CUMULATIVE work (the committed feature file), not just
    // the tiny incremental line — this is the fix for the infinite-reject loop.
    expect($sentToReviewer)->toContain('GroupScreenCumulativeMarker')
        ->and($sentToReviewer)->not->toContain('just a tiny incremental import');

    if (is_dir($repo)) {
        exec('rm -rf '.escapeshellarg($repo));
    }
});

it('falls back to the incremental diff when the task has no base_commit', function () {
    setupMemoryRoot();
    $project = Project::factory()->create();
    $execution = Execution::factory()->create(['project_id' => $project->id]);
    $task = Task::factory()->create([
        'project_id' => $project->id, 'task_key' => 'T-001',
        'worktree_path' => null, 'base_commit' => null,
        'status' => \App\Enums\TaskStatus::Reviewing,
    ]);
    $execution->tasks()->save($task);

    Node::factory()->create([
        'execution_id' => $execution->id, 'type' => 'build', 'status' => NodeStatus::Completed,
        'output' => ['diff' => '+legacy incremental diff content', 'filesChanged' => ['a.php']],
    ]);
    $reviewNode = Node::factory()->create(['execution_id' => $execution->id, 'type' => 'review', 'status' => NodeStatus::Pending]);

    $reviewer = bindCapturingReviewer();

    (new ReviewNode($reviewNode->id))->handle();

    $sent = collect($reviewer->last->messages)->firstWhere('role', 'user')['content'] ?? '';
    expect($sent)->toContain('legacy incremental diff content');
});
