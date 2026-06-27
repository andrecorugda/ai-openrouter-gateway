<?php

declare(strict_types=1);

use Andre\AiGateway\Support\Schema as GatewaySchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(GatewaySchema::connection())->create(
            GatewaySchema::table('invocations'),
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('ai_integration_id')->nullable();
                // Stable identifier even after the parent integration is deleted.
                $table->string('integration_slug_snapshot', 64)->nullable();

                $table->string('caller_type', 40);          // internal | api
                $table->string('caller_id', 64)->nullable(); // user id / token id

                $table->string('model_requested', 120)->nullable();
                $table->string('model_used', 120)->nullable();
                $table->unsignedTinyInteger('attempts')->default(1);

                $table->unsignedInteger('prompt_tokens')->nullable();
                $table->unsignedInteger('completion_tokens')->nullable();
                $table->unsignedInteger('cached_tokens')->nullable();
                $table->unsignedInteger('citation_count')->nullable();

                $table->decimal('cost_usd', 12, 6)->nullable();
                $table->unsignedInteger('latency_ms')->nullable();

                $table->enum('status', ['ok', 'fallback', 'error'])->index();
                $table->string('error_class', 120)->nullable();
                $table->text('error_message')->nullable();

                $table->string('openrouter_generation_id', 80)->nullable();
                $table->char('request_hash', 64)->nullable();

                $table->timestamp('created_at')->useCurrent();

                $table->index(['ai_integration_id', 'created_at']);
                $table->index(['caller_type', 'caller_id', 'created_at']);
            }
        );
    }

    public function down(): void
    {
        Schema::connection(GatewaySchema::connection())
            ->dropIfExists(GatewaySchema::table('invocations'));
    }
};
