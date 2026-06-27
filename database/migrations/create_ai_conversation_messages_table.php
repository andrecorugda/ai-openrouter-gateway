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
            GatewaySchema::table('conversation_messages'),
            function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('ai_conversation_id')->index();
                // Links each turn to its telemetry row (ai_invocations).
                $table->unsignedBigInteger('ai_invocation_id')->nullable()->index();
                $table->enum('role', ['user', 'assistant', 'system']);
                $table->longText('content');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['ai_conversation_id', 'created_at'], 'ai_conv_msg_conv_ts_idx');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(GatewaySchema::connection())
            ->dropIfExists(GatewaySchema::table('conversation_messages'));
    }
};
