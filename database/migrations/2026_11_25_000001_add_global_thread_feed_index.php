<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('threads', static function (Blueprint $table): void {
            $table->index(['is_pinned', 'updated_at', 'id', 'category_id'], 'threads_global_feed_index');
        });
    }

    public function down(): void
    {
        Schema::table('threads', static function (Blueprint $table): void {
            $table->dropIndex('threads_global_feed_index');
        });
    }
};
