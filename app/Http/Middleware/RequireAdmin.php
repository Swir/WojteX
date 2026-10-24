<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Never authorize administration from a role cached in an authenticated session.
 *
 * A locked, CURRENT administrator row protects the whole downstream request,
 * including sensitive reads, group grants, settings, plugins, categories and
 * imports. On row-locking transactional engines (e.g. MySQL/InnoDB), a ban or
 * demotion of the same account cannot commit between authorization and the
 * guarded response's database work. Nested DB::transaction calls are safe.
 * SQLite does not offer SELECT FOR UPDATE or equivalent row-level guarantees.
 */
class RequireAdmin
{
    public function handle(Request $request, Closure $next): mixed
    {
        $actorId = (int) ($request->user()?->id ?? 0);
        abort_unless($actorId > 0, 403, __('server.admin_only'));

        return DB::transaction(static function () use ($actorId, $request, $next): mixed {
            $current = DB::table('users')->where('id', $actorId)
                ->lockForUpdate()->first(['role', 'is_banned']);
            abort_unless($current && $current->role === 'admin' && !(bool) $current->is_banned,
                403, __('server.admin_only'));

            return $next($request);
        });
    }
}
