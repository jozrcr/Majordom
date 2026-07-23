<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-project, per-actor, per-DAY spend budgets (M14b). Shape:
 *   { "<role>": { "daily_cap_usd": float|null, "backup": "<role>"|null } }
 * A role absent (or null cap) is excluded/uncapped. When a capped actor's spend
 * for the day exceeds its cap, work routes to its backup actor (e.g. local Qwen).
 * See App\Core\Usage\SpendGuard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('actor_budgets')->nullable()->after('capability_level');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('actor_budgets');
        });
    }
};
