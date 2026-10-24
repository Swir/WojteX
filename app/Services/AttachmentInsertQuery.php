<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Build a single-statement authorization and quota guard for attachment
 * creation. Preflight request/session checks can become stale while the file
 * is staged on disk. This guard reads the current account and thread state
 * and the current attachment count at INSERT time. A caller must serialize
 * competing uploads with a lock on the post row before running this query.
 * Group/category grants remain checked by the controller's preflight and
 * are NOT atomically rechecked here. Native DB concurrency remains ungated.
 */
final class AttachmentInsertQuery
{
    public static function eligible(
        int $postId, int $threadId, int $actorId,
        string $path, string $name, string $mime, int $size, string $timestamp
    ) {
        return DB::table('posts as p')
            ->join('threads as t', 't.id', '=', 'p.thread_id')
            ->crossJoin('users as actor')
            ->where('p.id', $postId)
            ->where('p.thread_id', $threadId)
            ->where('actor.id', $actorId)
            ->where('actor.is_banned', false)
            ->where(fn ($query) => $query
                ->whereColumn('p.user_id', 'actor.id')
                ->orWhereIn('actor.role', ['admin', 'moderator']))
            ->where(fn ($query) => $query
                ->where('t.is_locked', false)
                ->orWhereIn('actor.role', ['admin', 'moderator']))
            ->whereRaw('(SELECT COUNT(*) FROM attachments AS existing WHERE existing.post_id = p.id) < 5')
            ->selectRaw(
                'p.id AS post_id, actor.id AS user_id, ? AS path, ? AS name, ? AS mime, ? AS size, ? AS created_at, ? AS updated_at',
                [$path, $name, $mime, $size, $timestamp, $timestamp]
            );
    }
}
