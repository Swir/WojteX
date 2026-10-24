<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Prevent stale staff sessions from reading private forum threads/files.
 *
 * The session may still say "admin" or "moderator" after the account has
 * been demoted or banned. Check the authoritative row under a transaction
 * lock, then use its role for the whole downstream forum read. On MySQL /
 * InnoDB a concurrent ban or demotion of this account cannot commit until
 * the response has been constructed. SQLite ignores row-level FOR UPDATE;
 * this does NOT serialize concurrent changes to group/category grants.
 * Guests retain access to public categories without a DB identity lock.
 */
class RequireFreshForumViewer
{
    public function handle(Request $request, Closure $next): mixed
    {
        $viewer = $request->user();
        if ($viewer === null) return $next($request);

        $viewerId = (int) ($viewer->id ?? 0);
        abort_unless($viewerId > 0, 403, __('server.account_blocked'));

        return DB::transaction(static function () use ($request, $next, $viewer, $viewerId): mixed {
            $current = DB::table('users')->where('id', $viewerId)
                ->lockForUpdate()->first(['id', 'role', 'is_banned']);
            abort_unless($current && (int) $current->id === $viewerId && !(bool) $current->is_banned,
                403, __('server.account_blocked'));

            // Authorization below uses $request->user() for category grants,
            // staff overrides, pagination and attachment visibility. Replace
            // stale privilege attributes before any of those checks execute.
            // Read-only endpoints must never save these temporary attributes.
            $viewer->role = (string) $current->role;
            $viewer->is_banned = false;
            return $next($request);
        });
    }
}
