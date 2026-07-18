<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opt-in actor rights (M14b): the Architect's repository-access tier per project.
 * Default 'read' — the floor the owner is opted into (T-66 reads). See
 * App\Enums\CapabilityLevel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('capability_level')->default('read')->after('confirm_commits');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('capability_level');
        });
    }
};
