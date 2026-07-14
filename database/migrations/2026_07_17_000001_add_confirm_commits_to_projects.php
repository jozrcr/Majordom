<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            // Opt-in per-task checkpoint: pause after review and show the diff
            // for the owner to eyeball before the loop advances (M12). Default
            // off — the milestone boundary is the only gate.
            $table->boolean('confirm_commits')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('confirm_commits');
        });
    }
};
