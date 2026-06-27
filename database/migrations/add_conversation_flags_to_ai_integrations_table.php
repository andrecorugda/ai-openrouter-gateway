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
        Schema::connection(GatewaySchema::connection())->table(
            GatewaySchema::table('integrations'),
            function (Blueprint $table) {
                $table->boolean('is_conversational')->default(false)->after('provider');
                $table->unsignedInteger('conversation_ttl_minutes')->nullable()->after('is_conversational');
            }
        );
    }

    public function down(): void
    {
        Schema::connection(GatewaySchema::connection())->table(
            GatewaySchema::table('integrations'),
            function (Blueprint $table) {
                $table->dropColumn(['is_conversational', 'conversation_ttl_minutes']);
            }
        );
    }
};
