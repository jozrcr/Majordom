<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\ApprovalType;
use App\Livewire\ProjectWorkspace;
use App\Models\Approval;
use App\Models\Project;
use Livewire\Livewire;
use Tests\TestCase;

class MilestoneGateUiTest extends TestCase
{
    public function test_milestone_merge_card_renders_correctly(): void
    {
        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::MilestoneMerge,
            'status' => ApprovalStatus::Open,
            'title' => 'M12: User Authentication',
            'payload' => [],
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->assertSee('Milestone complete')
            ->assertSee('M12: User Authentication')
            ->assertSee('Merge into main');
    }

    public function test_reject_milestone_merge_does_not_require_comment(): void
    {
        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::MilestoneMerge,
            'status' => ApprovalStatus::Open,
            'title' => 'M12: User Authentication',
            'payload' => [],
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('rejectApproval')
            ->assertHasNoErrors('gateComment');
    }

    public function test_reject_review_still_requires_comment(): void
    {
        $project = Project::factory()->create();
        Approval::create([
            'project_id' => $project->id,
            'type' => ApprovalType::Review,
            'status' => ApprovalStatus::Open,
            'title' => 'Review PR #42',
            'payload' => [],
        ]);

        Livewire::test(ProjectWorkspace::class, ['project' => $project])
            ->call('rejectApproval')
            ->assertHasErrors('gateComment');
    }
}
