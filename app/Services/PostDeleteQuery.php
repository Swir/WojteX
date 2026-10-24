<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Delete a non-original post only while its owner (in an unlocked topic) or
 * an active moderator/admin still has permission. The caller holds a parent
 * thread row lock and determines the first post within the same transaction.
 * Group/category grants are checked in the controller preflight, not here;
 * this does not prove full serializability against direct external DB writes.
 */
final class PostDeleteQuery
{
    /** @return \Illuminate\Database\Query\Builder */
    public static function eligible(int $postId, int $actorId, int $threadId, int $firstId)
    {
        return DB::table('posts')
            ->where('id', $postId)
            ->where('thread_id', $threadId)
            ->where('id', '!=', $firstId)
            ->whereExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('users as live_deleter')
                ->join('threads as live_thread', 'live_thread.id', '=', 'posts.thread_id')
                ->where('live_deleter.id', $actorId)
                ->where('live_deleter.is_banned', false)
                ->where(fn ($auth) => $auth
                    ->whereIn('live_deleter.role', ['admin', 'moderator'])
                    ->orWhere(fn ($owner) => $owner
                        ->whereColumn('posts.user_id', 'live_deleter.id')
                        ->where('live_thread.is_locked', false))));
    }
}
