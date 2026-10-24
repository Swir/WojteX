<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('forum_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->timestamps();
        });
        Schema::create('user_group_members', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('forum_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['group_id', 'user_id']);
        });
        Schema::create('category_group_permissions', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('forum_groups')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->boolean('allow_view')->default(false);
            $table->boolean('allow_create')->default(false);
            $table->boolean('allow_reply')->default(false);
            $table->primary(['group_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_group_permissions');
        Schema::dropIfExists('user_group_members');
        Schema::dropIfExists('forum_groups');
    }
};
