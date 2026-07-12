<?php

namespace App\Projects\Repositories;

use App\Core\Events\EventRecorder;
use App\Core\Workflow\ImplementFeatureWorkflow;
use App\Enums\TaskStatus;
use App\Models\CommitSuggestion;
use App\Models\Task;
use App\Projects\Memory\MemoryStore;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The human's commit verdict, executed (SPEC §3 phase 9). Every entry point
 * here runs because the owner clicked — Majordom never invokes this on its
 * own. Push stays out entirely.
 */
class CommitService
{
    public function __construct(
        private readonly WorktreeManager $worktrees,
        private readonly MemoryStore $memory,
        private readonly EventRecorder $events,
    ) {}

    /** Squash-promote the WIP branch into the user's checkout and commit. */
    public function apply(CommitSuggestion $suggestion): void
    {
        $this->assertSuggested($suggestion);
        $task = $suggestion->task;
        $repo = $suggestion->project->repo_path;

        $status = Process::path($repo)->run(['git', 'status', '--porcelain']);
        if (trim($status->output()) !== '') {
            throw new RuntimeException('Your working tree has uncommitted changes — commit or stash them first.');
        }

        $merge = Process::path($repo)->run(['git', 'merge', '--squash', $suggestion->branch]);
        if (! $merge->successful()) {
            throw new RuntimeException('Squash merge failed: '.trim($merge->errorOutput()));
        }

        $commit = Process::path($repo)->run(['git', 'commit', '-m', $suggestion->message]);
        if (! $commit->successful()) {
            // Undo the staged squash so the checkout is left as found.
            Process::path($repo)->run(['git', 'reset', '--merge']);
            throw new RuntimeException('Commit failed: '.trim($commit->errorOutput()));
        }

        $suggestion->update(['status' => 'committed']);
        if ($task) {
            $this->worktrees->remove($task); // branch kept for archaeology
        }

        $this->events->record($suggestion->project, 'commit.applied', [
            'branch' => $suggestion->branch,
            'task_key' => $task?->task_key,
        ], $suggestion->execution, 'you');
    }

    /** Owner comments become the next revision brief; a new run starts. */
    public function rework(CommitSuggestion $suggestion, string $comment): Task
    {
        $this->assertSuggested($suggestion);
        $task = $suggestion->task ?? throw new RuntimeException('Suggestion has no task.');
        $project = $suggestion->project;

        $base = $this->memory->read($project, "tasks/{$task->task_key}/task.md") ?? '';
        $next = $task->revision + 1;
        $this->memory->write(
            $project,
            "tasks/{$task->task_key}/task.v{$next}.md",
            $base."\n\n## Owner rework request (revision {$next})\n\n{$comment}\n",
        );
        $task->update(['revision' => $next]);
        $suggestion->update(['status' => 'discarded']);

        $this->events->record($project, 'commit.reworked', [
            'task_key' => $task->task_key, 'comment' => $comment, 'revision' => $next,
        ], $suggestion->execution, 'you');

        return ImplementFeatureWorkflow::startForTask($project, $task->task_key, $task->title);
    }

    /** Discard entirely: suggestion dead, worktree gone, branch kept. */
    public function reject(CommitSuggestion $suggestion, string $comment): void
    {
        $this->assertSuggested($suggestion);
        $suggestion->update(['status' => 'discarded']);

        if ($task = $suggestion->task) {
            $task->update(['status' => TaskStatus::Failed]);
            $this->worktrees->remove($task);
        }

        $this->events->record($suggestion->project, 'commit.rejected', [
            'task_key' => $suggestion->task?->task_key, 'comment' => $comment,
        ], $suggestion->execution, 'you');
    }

    private function assertSuggested(CommitSuggestion $suggestion): void
    {
        if ($suggestion->status !== 'suggested') {
            throw new RuntimeException('This suggestion was already resolved.');
        }
    }
}
