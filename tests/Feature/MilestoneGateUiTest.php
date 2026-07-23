<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Livewire\ProjectWorkspace;
use App\Models\Approval;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MilestoneGateUiTest extends TestCase
{
    use RefreshDatabase;

    /** A frozen recap like MilestoneRecap::for() builds into the gate payload. */
    private function recapPayload(): array
    {
        return [
            'milestone_id' => 0,
            'profile' => 'attended',
            'recap' => [
                'milestone_key' => 'M12',
                'title' => 'User Authentication',
                'goal' => 'Let people sign in and out.',
                'branch' => 'majordom/M12',
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
}
