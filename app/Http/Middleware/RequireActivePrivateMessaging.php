<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Protect *all* private-message reads as well as mutations from stale sessions.
 *
 * A session can retain an unbanned User after a concurrent administrator bans
 * or removes the account. In particular the inbox, recipient search, blocked
 * contacts and read receipts must not trust that cached User object. Acquire
 * the current user row and hold a row lock for the whole downstream response.
 * MySQL/InnoDB can then serialize a ban/delete against this PM request;
 * SQLite ignores SELECT FOR UPDATE and is NOT a native concurrency guarantee.
 */
class RequireActivePrivateMessaging
{
    public function handle(Request $request, Closure $next): mixed
    {
        $id = (int) ($request->user()?->id ?? 0);
        abort_unless($id > 0, 403, __('server.messages_banned'));

        return DB::transaction(static function () use ($id, $request, $next): mixed {
            $actor = DB::table('users')->where('id', $id)
                ->lockForUpdate()->first(['id', 'is_banned']);
            abort_unless($actor && !(bool) $actor->is_banned,
                403, __('server.messages_banned'));

            return $next($request);
        });
    }
}
