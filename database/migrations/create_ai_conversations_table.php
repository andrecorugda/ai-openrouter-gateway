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
            GatewaySchema::table('conversations'),
            function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->unsignedBigInteger('ai_integration_id')->index();
                $table->string('caller_type', 40);
                $table->string('caller_id', 64)->nullable();
                $table->enum('status', ['active', 'closed'])->default('active');
                $table->json('metadata')->nullable();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->unsignedInteger('message_count')->default(0);
                $table->timestamps();
                $table->softDeletes();
            }
        );
    }

    public function down(): void
    {
        Schema::connection(GatewaySchema::connection())
            ->dropIfExists(GatewaySchema::table('conversations'));
    }
};
