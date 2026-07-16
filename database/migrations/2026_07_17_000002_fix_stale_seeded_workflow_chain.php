<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M12 moved the default chain tail from `commit_suggestion` to `finalize`
 * (the checkpoint is now the per-project `confirm_commits` toggle), but the
 * seeded "Implement Feature" workflow row froze the pre-M12 chain and — via
 * chainFor()'s "custom workflow wins" branch — silently re-imposed a
 * per-commit approval prompt on every bound project, including full-auto
 * runs. Rewrite exactly that stale seeded shape; deliberately customized
 * chains are left untouched (and chainFor() now normalizes the tail at
 * read time anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('workflows')
            ->where('name', 'Implement Feature')
            ->whereJsonContains('chain', 'commit_suggestion')
            ->update([
                'chain' => json_encode(['delegate', 'build', 'test', 'review', 'finalize']),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // No rollback: the old chain shape is the bug.
    }
};
