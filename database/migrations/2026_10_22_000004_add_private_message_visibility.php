<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('private_messages', function (Blueprint $table) {
            $table->timestamp('sender_hidden_at')->nullable();
            $table->timestamp('recipient_hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('private_messages', function (Blueprint $table) {
            $table->dropColumn(['sender_hidden_at', 'recipient_hidden_at']);
        });
    }
};
