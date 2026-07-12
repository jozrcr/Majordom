<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('execution_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->string('model');
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->decimal('cost_usd', 12, 8)->nullable();
            $table->timestamp('created_at')->useCurrent();
            
            $table->index(['project_id', 'created_at']);
            $table->index(['project_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
