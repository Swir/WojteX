<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('threads', static function (Blueprint $table): void {
            $table->index(['is_pinned', 'created_at', 'id'], 'threads_creation_feed_index');
        });
    }

    public function down(): void
    {
        Schema::table('threads', static function (Blueprint $table): void {
            $table->dropIndex('threads_creation_feed_index');
        });
    }
};
