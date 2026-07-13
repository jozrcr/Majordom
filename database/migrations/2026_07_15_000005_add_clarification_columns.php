<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            // Reviewer-escalated questions belong to an execution, not a
            // consensus message.
            $table->foreignId('execution_id')->nullable()->constrained()->cascadeOnDelete();
        });
        Schema::table('tasks', function (Blueprint $table) {
            // Human clarification resets the revision budget: the guard
            // counts revisions since the last human input, not lifetime.
            $table->unsignedInteger('clarified_at_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('execution_id');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('clarified_at_revision');
        });
    }
};
