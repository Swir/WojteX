<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('thread_follows', static function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            // This watermark remains valid when the post itself is deleted.
            $table->unsignedBigInteger('last_read_post_id')->default(0);
            $table->primary(['user_id', 'thread_id']);
            $table->index('thread_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('thread_follows');
    }
};
