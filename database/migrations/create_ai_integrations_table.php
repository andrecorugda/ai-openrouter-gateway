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
            GatewaySchema::table('integrations'),
            function (Blueprint $table) {
                $table->id();

                $table->string('slug', 64)->unique();      // immutable identity
                $table->string('name', 120);
                $table->text('description')->nullable();

                $table->boolean('is_active')->default(true)->index();
                $table->string('provider', 60)->default('openrouter');

                $table->boolean('supports_vision')->default(false);
                $table->boolean('supports_tools')->default(false);

                // internal = PHP callers only; public/both = reachable via HTTP API.
                $table->enum('visibility', ['internal', 'public', 'both'])->default('internal');

                // Per-integration guardrails. null => fall back to config defaults.
                $table->unsignedInteger('rate_limit_per_minute')->nullable();
                $table->decimal('max_daily_cost_usd', 10, 2)->nullable();

                // Soft FK to the host app's user table (no constraint — connection
                // may differ from the package's).
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();
                $table->softDeletes();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(GatewaySchema::connection())
            ->dropIfExists(GatewaySchema::table('integrations'));
    }
};
