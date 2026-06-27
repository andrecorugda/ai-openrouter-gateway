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
            GatewaySchema::table('integration_versions'),
            function (Blueprint $table) {
                $table->id();

                $table->unsignedBigInteger('ai_integration_id')->index();
                $table->unsignedInteger('version_number')->default(1);

                // Exactly one active version per integration (enforced in code).
                $table->boolean('is_active')->default(false);

                // --- editable surface -------------------------------------------------
                $table->longText('system_prompt')->nullable();
                $table->boolean('system_prompt_cacheable')->default(true);

                // Ordered model list: primary first, then fallbacks.
                $table->json('models');
                // Forwarded-to-OpenRouter generation params (max_tokens, temperature...).
                $table->json('default_params')->nullable();
                // Declared variable schema for the prompt template.
                $table->json('prompt_args')->nullable();
                // OpenRouter server tools (web_search / web_fetch) config.
                $table->json('server_tools')->nullable();

                $table->string('notes', 255)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();

                $table->timestamps();

                $table->unique(['ai_integration_id', 'version_number']);
            }
        );
    }

    public function down(): void
    {
        Schema::connection(GatewaySchema::connection())
            ->dropIfExists(GatewaySchema::table('integration_versions'));
    }
};
