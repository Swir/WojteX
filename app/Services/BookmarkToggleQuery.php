<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Bookmark toggles must not keep write access from a stale session/preflight.
 * Both SQL paths require a currently existing, non-banned account and thread.
 * The category/group visibility check is performed separately by Forum::thread
 * and is NOT atomically revalidated by these queries.
 */
final class BookmarkToggleQuery
{
    public static function eligibleInsert(int $threadId, int $actorId, string $time)
    {
        return DB::table('threads as t')
            ->crossJoin('users as actor')
            ->where('t.id', $threadId)
            ->where('actor.id', $actorId)
            ->where('actor.is_banned', false)
            ->selectRaw(
                't.id AS thread_id, actor.id AS user_id, ? AS created_at, ? AS updated_at',
                [$time, $time]
            );
    }

    public static function eligibleDelete(int $threadId, int $actorId)
    {
        return DB::table('bookmarks')
            ->where('thread_id', $threadId)
            ->where('user_id', $actorId)
            ->whereExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('threads as t')
                ->crossJoin('users as actor')
                ->whereColumn('t.id', 'bookmarks.thread_id')
                ->whereColumn('actor.id', 'bookmarks.user_id')
                ->where('actor.is_banned', false));
    }
}
