<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 20)->default('member')->index();
            $table->text('bio')->nullable();
            $table->string('color', 20)->default('violet');
            $table->boolean('is_banned')->default(false);
            $table->string('legacy_password_hash', 64)->nullable();
            $table->string('legacy_password_salt', 64)->nullable();
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('slug')->unique();
            $table->text('description')->nullable(); $table->string('color', 20)->default('violet');
            $table->string('icon', 30)->default('code');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->integer('sort_order')->default(0); $table->boolean('is_private')->default(false)->index();
            $table->timestamps();
        });
        Schema::create('threads', function (Blueprint $table) {
            $table->id(); $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('title', 180); $table->string('slug');
            $table->boolean('is_pinned')->default(false); $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('views')->default(0); $table->timestamps();
            $table->index(['category_id', 'is_pinned', 'updated_at']); $table->index('updated_at');
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->id(); $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->longText('body'); $table->string('body_format', 20)->default('markdown');
            $table->timestamps(); $table->index(['thread_id', 'id']);
        });
        Schema::create('reactions', function (Blueprint $table) {
            $table->id(); $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->timestamps();
            $table->unique(['post_id', 'user_id']);
        });
        Schema::create('bookmarks', function (Blueprint $table) {
            $table->id(); $table->foreignId('thread_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->timestamps();
            $table->unique(['thread_id', 'user_id']);
        });
        Schema::create('shouts', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body'); $table->timestamps();
        });
        Schema::create('attachments', function (Blueprint $table) {
            $table->id(); $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('path'); $table->string('name'); $table->string('mime', 100);
            $table->unsignedBigInteger('size'); $table->timestamps();
        });
        Schema::create('settings', function (Blueprint $table) { $table->string('key')->primary(); $table->text('value'); });
        Schema::create('plugin_states', function (Blueprint $table) {
            $table->string('key')->primary(); $table->boolean('enabled')->default(false); $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action'); $table->text('details'); $table->timestamps();
        });
        Schema::create('import_maps', function (Blueprint $table) {
            $table->id(); $table->string('source', 120); $table->string('entity', 30);
            $table->string('source_id', 64); $table->unsignedBigInteger('target_id');
            $table->unique(['source', 'entity', 'source_id']);
        });
        Schema::create('import_runs', function (Blueprint $table) {
            $table->id(); $table->string('source', 120); $table->string('fingerprint', 64)->unique();
            $table->string('status', 30); $table->longText('report'); $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['import_runs','import_maps','audit_logs','plugin_states','settings','attachments','shouts','bookmarks','reactions','posts','threads','categories'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role','bio','color','is_banned','legacy_password_hash','legacy_password_salt']));
    }
};
