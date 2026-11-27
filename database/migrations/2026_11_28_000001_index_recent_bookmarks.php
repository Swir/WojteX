<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookmarks', function (Blueprint $table): void {
            $table->index(['user_id', 'created_at', 'id'], 'bookmarks_saved_order_index');
        });
    }

    public function down(): void
    {
        // MySQL can replace the implicit foreign-key index with the new composite index.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)
            && !Schema::hasIndex('bookmarks', ['user_id'])) {
            Schema::table('bookmarks', fn (Blueprint $table) => $table->index('user_id'));
        }

        Schema::table('bookmarks', fn (Blueprint $table) => $table->dropIndex('bookmarks_saved_order_index'));
    }
};
