<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_endpoints', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->string('driver')->default('openai_compatible');
            $table->string('base_url');
            $table->text('api_key')->nullable();
            $table->unsignedInteger('timeout')->default(120);
            $table->json('meta')->nullable();
            $table->boolean('is_builtin')->default(false);
            $table->timestamps();
        });

        $now = now()->toDateTimeString();
        DB::table('provider_endpoints')->insert([
            [
                'name' => 'openrouter',
                'label' => 'OpenRouter',
                'driver' => 'openai_compatible',
                'base_url' => config('majordom.providers.openrouter.base_url'),
                'api_key' => null,
                'timeout' => config('majordom.providers.openrouter.timeout', 120),
                'meta' => json_encode(['api_key_config' => 'majordom.providers.openrouter.api_key']),
                'is_builtin' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'metallama',
                'label' => 'metallama (local)',
                'driver' => 'metallama',
                'base_url' => config('majordom.metallama.base_url'),
                'api_key' => null,
                'timeout' => config('majordom.providers.openrouter.timeout', 120),
                'meta' => null,
                'is_builtin' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_endpoints');
    }
};
