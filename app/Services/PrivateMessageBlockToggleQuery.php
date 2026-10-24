<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Blocking/unblocking PM contact must not use stale session ban state.
 * Guard the actor's current existence and ban in the actual SQL mutation.
 * A guarded insert also requires a live target, preserving the foreign key.
 */
final class PrivateMessageBlockToggleQuery
{
    public static function activeActor(int $actorId)
    {
        return DB::table('users as actor')
            ->where('actor.id', $actorId)
            ->where('actor.is_banned', false);
    }

    public static function eligibleInsert(int $actorId, int $otherId, string $time)
    {
        return self::activeActor($actorId)
            ->crossJoin('users as other')
            ->where('other.id', $otherId)
            ->whereColumn('actor.id', '!=', 'other.id')
            ->selectRaw('actor.id AS blocker_id, other.id AS blocked_id, ? AS created_at, ? AS updated_at',
                [$time, $time]);
    }

    public static function eligibleDelete(int $actorId, int $otherId)
    {
        return DB::table('private_message_blocks')
            ->where('blocker_id', $actorId)
            ->where('blocked_id', $otherId)
            ->whereExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('users as actor')
                ->whereColumn('actor.id', 'private_message_blocks.blocker_id')
                ->where('actor.is_banned', false));
    }
}
