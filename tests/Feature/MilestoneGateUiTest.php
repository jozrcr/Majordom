<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Livewire\ProjectWorkspace;
use App\Models\Approval;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;

class MilestoneGateUiTest extends TestCase
{
    use RefreshDatabase;

    /** A frozen recap like MilestoneRecap::for() builds into the gate payload. */
    private function recapPayload(?string $worktree = '/home/user/.majordom/worktrees/proj/M12'): array
    {
        return [
            'milestone_id' => 0,
            'profile' => 'attended',
            'recap' => [
                'milestone_key' => 'M12',
                'title' => 'User Authentication',
                'goal' => 'Let people sign in and out.',
                'branch' => 'majordom/M12',
                'worktree' => $worktree,
                'diffstat' => ['files' => 4, 'insertions' => 120, 'deletions' => 8],
                'review_summary' => 'Clean; covers the acceptance criteria.',
                'tasks' => [
                    ['key' => 'T-001', 'title' => 'Login form', 'criteria' => "- form validates\n- shows errors"],
                ],
                'how_to_test' => 'Run `php artisan serve`, visit /login, sign in.',
            ],
        ];
    }

    public function test_milestone_merge_card_renders_recap_and_three_actions(): void
    {
        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::MilestoneMerge,
            'status' => ApprovalStatus::Open,
            'title' => 'M12: User Authentication',
            'payload' => $this->recapPayload(),
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->assertSee('Milestone complete')
            ->assertSee('M12: User Authentication')
            // Recap surfaces the goal, diffstat, verdict and how-to-test (M16-A).
            ->assertSee('Let people sign in and out.')
            ->assertSee('Clean; covers the acceptance criteria.')
            ->assertSee('How to test it yourself')
            ->assertSee('Run `php artisan serve`')
            // Three distinct resolutions, never a single "not yet" dead end.
            ->assertSee('Merge into main')
            ->assertSee('Send back to the Architect')
            ->assertSee('Not yet — keep it ready');
    }

    public function test_the_gate_still_renders_when_the_payload_has_no_recap(): void
    {
        // Old gates raised before M16-A have an empty payload — the modal must
        // degrade gracefully, not error on a missing recap.
        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::MilestoneMerge,
            'status' => ApprovalStatus::Open,
            'title' => 'M9: Legacy gate',
            'payload' => [],
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->assertSee('Milestone complete')
            ->assertSee('M9: Legacy gate')
            ->assertSee('Merge into main');
    }

    public function test_not_yet_defers_the_gate_without_a_comment(): void
    {
        $project = Project::factory()->create();
        $gate = Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::MilestoneMerge,
            'status' => ApprovalStatus::Open,
            'title' => 'M12: User Authentication',
            'payload' => $this->recapPayload(),
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('deferMilestone')
            ->assertHasNoErrors('gateComment');

        $this->assertSame(ApprovalStatus::Deferred, $gate->fresh()->status);
    }

    public function test_send_back_requires_a_reason(): void
    {
        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::MilestoneMerge,
            'status' => ApprovalStatus::Open,
            'title' => 'M12: User Authentication',
            'payload' => $this->recapPayload(),
        ]);

        // Empty reason → the fix brief would be empty; block it.
        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('requestGateChanges')
            ->assertHasErrors('gateComment');
    }

    public function test_a_deferred_gate_surfaces_a_merge_later_affordance(): void
    {
        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::MilestoneMerge,
            'status' => ApprovalStatus::Deferred,
            'title' => 'M12: User Authentication',
            'payload' => $this->recapPayload(),
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->assertSee('Merge later — ready when you are')
            ->assertSee('M12: User Authentication')
            ->assertSee('Merge now');
    }

    public function test_reject_review_still_requires_comment(): void
    {
        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::Review,
            'status' => ApprovalStatus::Open,
            'title' => 'Review PR #42',
            'payload' => ['testsPassed' => true, 'filesChanged' => [], 'verdict' => ['summary' => 'ok'], 'diff' => ''],
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('rejectApproval')
            ->assertHasErrors('gateComment');
    }

    public function test_the_gate_shows_the_worktree_path_and_branch_with_an_open_action(): void
    {
        // M16-C: the disposable worktree is made legible — the owner sees where
        // the work physically lives (path + branch) and can open it in one click.
        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::MilestoneMerge,
            'status' => ApprovalStatus::Open,
            'title' => 'M12: User Authentication',
            'payload' => $this->recapPayload('/home/user/.majordom/worktrees/proj/M12'),
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->assertSee('majordom/M12')
            ->assertSee('/home/user/.majordom/worktrees/proj/M12')
            ->assertSee('Open in VS Code');
    }

    public function test_open_in_editor_launches_the_editor_on_the_gate_worktree(): void
    {
        Process::fake();

        $worktree = sys_get_temp_dir().'/majordom-open-wt-'.uniqid();
        mkdir($worktree, 0755, true);

        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::MilestoneMerge,
            'status' => ApprovalStatus::Open,
            'title' => 'M12: User Authentication',
            'payload' => $this->recapPayload($worktree),
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('openInEditor');

        Process::assertRan(fn ($run) => $run->path === $worktree
            && $run->command === ['code', $worktree]);

        $this->assertDatabaseHas('events', [
            'project_id' => $project->id,
            'name' => 'editor.opened',
        ]);
    }

    public function test_open_in_editor_falls_back_to_the_project_folder(): void
    {
        Process::fake();

        $repo = sys_get_temp_dir().'/majordom-open-repo-'.uniqid();
        mkdir($repo, 0755, true);

        // No open gate → no recap worktree; the fallback is the project folder.
        $project = Project::factory()->create(['repo_path' => $repo]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('openInEditor');

        Process::assertRan(fn ($run) => $run->command === ['code', $repo]);
    }

    public function test_open_in_editor_no_ops_when_no_directory_exists(): void
    {
        Process::fake();

        $project = Project::factory()->create(['repo_path' => '/no/such/dir-'.uniqid()]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('openInEditor')
            ->assertSet('runNotice', 'No folder to open yet — the worktree has not been created.');

        Process::assertNothingRan();
    }
}
