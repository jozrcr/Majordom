<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('task_key');
            $table->string('title');
            $table->string('branch')->nullable();
            $table->string('worktree_path')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
