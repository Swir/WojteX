<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * A stale authenticated User model is not sufficient to moderate a topic.
 * Recheck the current staff role and ban flag in the same SQL UPDATE.
 * This is statement-level authorization, not global transaction serialization.
 */
final class ThreadModerateQuery
{
    public static function eligible(int $threadId, int $actorId)
    {
        return DB::table('threads')
            ->where('id', $threadId)
            ->whereExists(static fn ($q) => $q->selectRaw('1')
                ->from('users as current_moderator')
                ->where('current_moderator.id', $actorId)
                ->where('current_moderator.is_banned', false)
                ->whereIn('current_moderator.role', ['admin', 'moderator']));
    }
}
