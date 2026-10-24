<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Require live account ownership/staff role and thread lock in the post UPDATE.
 * Old request User/Post/Thread snapshots may be stale after category preflight.
 * This does not atomically recheck mutable category/group visibility grants.
 */
final class PostEditQuery
{
    public static function eligible(int $postId, int $actorId)
    {
        return DB::table('posts as p')
            ->where('p.id', $postId)
            ->whereExists(fn ($q) => $q
                ->selectRaw('1')
                ->from('users as live_editor')
                ->join('threads as live_thread', 'live_thread.id', '=', 'p.thread_id')
                ->where('live_editor.id', $actorId)
                ->where('live_editor.is_banned', false)
                ->where(fn ($auth) => $auth
                    ->whereIn('live_editor.role', ['admin', 'moderator'])
                    ->orWhere(fn ($owner) => $owner
                        ->whereColumn('p.user_id', 'live_editor.id')
                        ->where('live_thread.is_locked', false))));
    }
}
