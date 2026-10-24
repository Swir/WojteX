<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Re-check the actor at the SQL mutation boundary, not against the request's
 * potentially stale authenticated User model. Both changes require a live,
 * unbanned, non-author account and an existing post in an existing thread.
 * Category/group visibility is still validated at controller preflight; this
 * does not make grants/ancestry checks atomic or qualify concurrent HTTP.
 */
final class ReactionToggleQuery
{
    public static function eligibleInsert(int $postId, int $actorId, string $time)
    {
        return DB::table('posts as p')
            ->join('threads as t', 't.id', '=', 'p.thread_id')
            ->crossJoin('users as actor')
            ->where('p.id', $postId)
            ->where('actor.id', $actorId)
            ->where('actor.is_banned', false)
            ->whereColumn('p.user_id', '<>', 'actor.id')
            ->selectRaw(
                'p.id AS post_id, actor.id AS user_id, ? AS created_at, ? AS updated_at',
                [$time, $time]
            );
    }

    public static function eligibleDelete(int $postId, int $actorId)
    {
        return DB::table('reactions')
            ->where('post_id', $postId)
            ->where('user_id', $actorId)
            ->whereExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('posts as p')
                ->join('threads as t', 't.id', '=', 'p.thread_id')
                ->crossJoin('users as actor')
                ->whereColumn('p.id', 'reactions.post_id')
                ->whereColumn('actor.id', 'reactions.user_id')
                ->where('actor.is_banned', false)
                ->whereColumn('p.user_id', '<>', 'actor.id'));
    }
}
