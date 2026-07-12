<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The inbound-reply map (SPEC §2 Notification): which Telegram
        // message corresponds to which pending decision, so a reply or
        // callback resolves the right thing.
        Schema::create('telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // question | reject_reason | commit_reject_reason | info
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->unsignedBigInteger('message_id'); // telegram's message id
            $table->timestamp('created_at')->nullable();
            $table->index(['message_id']);
            $table->index(['kind', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_messages');
    }
};
