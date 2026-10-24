<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Thread moderation deletion requires the CURRENT staff role and active
 * account in the same SQL DELETE. A cached request User is not an authority
 * if an admin revoked moderation privileges after the initial route check.
 */
final class ThreadDeleteQuery
{
    /** @return \Illuminate\Database\Query\Builder */
    public static function eligible(int $threadId, int $actorId)
    {
        return DB::table('threads')
            ->where('id', $threadId)
            ->whereExists(static fn ($q) => $q->selectRaw('1')
                ->from('users as current_deleter')
                ->where('current_deleter.id', $actorId)
                ->where('current_deleter.is_banned', false)
                ->whereIn('current_deleter.role', ['admin', 'moderator']));
    }
}
