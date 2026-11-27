<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('private_messages', function (Blueprint $table): void {
            $table->index(['recipient_id', 'recipient_hidden_at', 'read_at', 'id'], 'private_messages_unread_index');
        });
    }

    public function down(): void
    {
        Schema::table('private_messages', fn (Blueprint $table) => $table->dropIndex('private_messages_unread_index'));
    }
};
