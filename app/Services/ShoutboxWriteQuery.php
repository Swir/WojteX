<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Authorize a shout against a current database row in the INSERT itself.
 * A session User snapshot alone does not protect the gap before insertion.
 */
final class ShoutboxWriteQuery
{
    /** @return Builder */
    public static function eligible(int $userId, string $body, string $timestamp)
    {
        return DB::table('users')
            ->selectRaw('? AS user_id, ? AS body, ? AS created_at, ? AS updated_at',
                [$userId, $body, $timestamp, $timestamp])
            ->where('users.id', $userId)
            ->where('users.is_banned', false)
            // An administrator may disable the plugin after the initial
            // ExtensionRegistry preflight. Check the CURRENT plugin state in
            // the same INSERT...SELECT; a missing state is disabled.
            ->whereExists(static fn ($q) => $q->selectRaw('1')
                ->from('plugin_states as live_shoutbox')
                ->where('live_shoutbox.key', 'shoutbox')
                ->where('live_shoutbox.enabled', true));
    }
}
