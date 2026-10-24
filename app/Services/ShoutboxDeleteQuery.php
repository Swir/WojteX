<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A shout deletion is authorized against the CURRENT account state in the
 * DELETE statement, not only against a potentially stale session snapshot.
 * The same predicate protects an owner's deletion and a staff deletion after
 * an admin revokes moderator privileges during the request.
 */
final class ShoutboxDeleteQuery
{
    /** @return Builder */
    public static function eligible(int $shoutId, int $actorId)
    {
        return DB::table('shouts')
            ->where('id', $shoutId)
            // Deactivating the shoutbox must revoke DELETE too, not only
            // new posts. Check the module state during the DELETE itself.
            ->whereExists(static fn ($q) => $q->selectRaw('1')
                ->from('plugin_states as live_shoutbox')
                ->where('live_shoutbox.key', 'shoutbox')
                ->where('live_shoutbox.enabled', true))
            ->whereExists(static fn ($q) => $q->selectRaw('1')
                ->from('users as current_actor')
                ->where('current_actor.id', $actorId)
                ->where('current_actor.is_banned', false))
            ->where(static function ($q) use ($actorId) {
                $q->where('user_id', $actorId)
                    ->orWhereExists(static fn ($staff) => $staff->selectRaw('1')
                        ->from('users as current_staff')
                        ->where('current_staff.id', $actorId)
                        ->where('current_staff.is_banned', false)
                        ->whereIn('current_staff.role', ['admin', 'moderator']));
            });
    }
}
