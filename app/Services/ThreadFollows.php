<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

final class ThreadFollows
{
    public static function filter(Builder $threads, int $viewerId, bool $unreadOnly): Builder
    {
        $threads->join('thread_follows as f', static function (JoinClause $join) use ($viewerId): void {
            $join->on('f.thread_id', '=', 't.id')->where('f.user_id', $viewerId);
        })->selectSub(self::firstUnread($viewerId), 'first_unread_post_id');

        if ($unreadOnly) {
            $threads->whereExists(self::unreadPosts($viewerId)->selectRaw('1'));
        }

        return $threads;
    }

    public static function status(int $threadId, int $viewerId): ?array
    {
        $follow = DB::table('thread_follows as f')->where('f.thread_id', $threadId)->where('f.user_id', $viewerId)
            ->select('f.last_read_post_id')->selectSub(self::firstUnread($viewerId), 'first_unread_post_id')->first();

        return $follow ? [
            'last_read_post_id' => (int) $follow->last_read_post_id,
            'first_unread_post_id' => $follow->first_unread_post_id === null ? null : (int) $follow->first_unread_post_id,
        ] : null;
    }

    public static function writableFollow(int $threadId, int $viewerId): Builder
    {
        return DB::table('thread_follows')->where('thread_id', $threadId)->where('user_id', $viewerId)
            ->whereExists(self::activeViewer($viewerId));
    }

    public static function activeViewer(int $viewerId): Builder
    {
        return DB::table('users')->where('id', $viewerId)->where('is_banned', false)->selectRaw('1');
    }

    private static function firstUnread(int $viewerId): Builder
    {
        return self::unreadPosts($viewerId)->select('p.id')->orderBy('p.id')->limit(1);
    }

    private static function unreadPosts(int $viewerId): Builder
    {
        return DB::table('posts as p')->whereColumn('p.thread_id', 'f.thread_id')
            ->whereColumn('p.id', '>', 'f.last_read_post_id')->where('p.user_id', '<>', $viewerId);
    }
}
