<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Re-check both the live account and the live thread lock in the reply INSERT.
 * The request's User and Thread snapshots may become stale after preflight.
 * This does not revalidate mutable category/group permissions atomically.
 */
final class ThreadReplyQuery
{
    public static function eligible(int $actorId, int $threadId, string $body, string $timestamp)
    {
        return DB::table('threads as t')
            ->crossJoin('users as u')
            ->where('t.id', $threadId)
            ->where('u.id', $actorId)
            ->where('u.is_banned', false)
            ->where(fn ($query) => $query
                ->where('t.is_locked', false)
                ->orWhereIn('u.role', ['admin', 'moderator']))
            ->selectRaw(
                't.id AS thread_id, u.id AS user_id, ? AS body, ? AS body_format, ? AS created_at, ? AS updated_at',
                [$body, 'markdown', $timestamp, $timestamp]
            );
    }
}
