<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('upload_key', 32)->nullable();
            $table->unique(['upload_key', 'post_id', 'user_id'], 'attachments_upload_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', fn (Blueprint $table) => $table->dropUnique('attachments_upload_key_unique'));
        Schema::table('attachments', fn (Blueprint $table) => $table->dropColumn('upload_key'));
    }
};
