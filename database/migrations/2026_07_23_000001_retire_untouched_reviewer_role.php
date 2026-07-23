<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M16-D: the Reviewer is the Architect (one mind). Earlier installs seeded a
 * distinct built-in `reviewer` Role row whose model defaulted to the Architect's
 * — presenting a "second model" the owner never chose. Retire that row IFF it is
 * still the untouched built-in default (is_builtin, global, mirroring the
 * Architect's model). The reviewer role then resolves to the Architect via
 * RoleResolver's fallback. An owner who deliberately bound a distinct reviewer
 * (a different model, or MAJORDOM_REVIEWER_MODEL → `distinct`) is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (config('majordom.reviewer.distinct')) {
            return; // owner opted into a distinct reviewer — keep it
        }

        DB::table('roles')
            ->whereNull('project_id')
            ->where('name', 'reviewer')
            ->where('is_builtin', true)
            ->where('model', config('majordom.architect.model'))
            ->delete();
    }

    public function down(): void
    {
        // No rollback: re-seeding a distinct reviewer is the seeder's job, gated
        // on `majordom.reviewer.distinct`.
    }
};
