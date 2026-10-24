<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Installation must never run migrations against a foreign or occupied DB.
 * This check runs AFTER validating the local install token and taking the
 * install file lock, but BEFORE Artisan migrate touches any schema.
 *
 * Existing empty WojteX tables and known migration history are tolerated for
 * recovery of a partially initialized installation. An unrelated table,
 * unknown migration or pre-existing application data fails closed.
 */
final class InstallDatabaseGuard
{
    private const OWN_TABLES = [
        'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs', 'categories', 'threads', 'posts',
        'reactions', 'bookmarks', 'shouts', 'attachments', 'settings',
        'plugin_states', 'audit_logs', 'import_maps', 'import_runs',
        'forum_groups', 'user_group_members', 'category_group_permissions',
        'private_messages', 'private_message_blocks', 'private_message_reports',
        'migrations',
    ];

    public static function assertSafeBeforeMigrate(): void
    {
        // Requesting unqualified names is intentional: DB::table() needs the
        // table name, and the current configured connection is authoritative.
        $tables = Schema::getTableListing(null, false);
        foreach ($tables as $table) {
            abort_unless(is_string($table) && in_array($table, self::OWN_TABLES, true),
                409, __('install.errors.occupied_database'));

            if ($table === 'users') {
                // InstallController already rejected a nonempty users table.
                continue;
            }
            if ($table === 'migrations') {
                // A migration history from ANOTHER Laravel application can
                // cause Artisan to skip or misapply WojteX migrations.
                $files = glob(database_path('migrations/*.php')) ?: [];
                $known = array_map(static fn (string $file): string =>
                    pathinfo($file, PATHINFO_FILENAME), $files);
                abort_if($known === [] || DB::table('migrations')
                    ->whereNotIn('migration', $known)->exists(),
                    409, __('install.errors.occupied_database'));
                continue;
            }
            // A nonempty categories/posts/settings/etc. table must NEVER be
            // mistaken for a clean forum just because users is empty.
            abort_if(DB::table($table)->exists(),
                409, __('install.errors.occupied_database'));
        }
    }
}
