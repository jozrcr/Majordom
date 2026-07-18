<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The worktree commit a task's build started from (M14b). The Reviewer diffs
 * base_commit..worktree so it judges the task's CUMULATIVE work across all its
 * build revisions — not just the last aider run's incremental change (which,
 * for a smart Builder that edits minimally, is tiny and gets falsely rejected
 * against the full acceptance criteria). See ReviewNode / DelegateNode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('base_commit')->nullable()->after('revision');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('base_commit');
        });
    }
};
