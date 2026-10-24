<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Bind a mailbox hide to the actor's *current* persisted authorization. */
final class PrivateMessageHideQuery
{
    public static function activeActor(int $viewerId): Builder
    {
        return DB::table('users')->where('id', $viewerId)->where('is_banned', false);
    }

    /**
     * Update only this participant's visible copy, never the other copy.
     * Account existence and ban are checked in the very UPDATE that hides it.
     * This is statement-scoped; it is not a proof of cross-transaction isolation.
     */
    public static function eligible(int $viewerId, int $messageId, bool $sender): Builder
    {
        $owner = $sender ? 'sender_id' : 'recipient_id';
        $hidden = $sender ? 'sender_hidden_at' : 'recipient_hidden_at';

        return DB::table('private_messages')
            ->where('id', $messageId)
            ->where($owner, $viewerId)
            ->whereNull($hidden)
            ->whereExists(static fn (Builder $q) => $q
                ->selectRaw('1')
                ->from('users as live_hider')
                ->whereColumn('live_hider.id', 'private_messages.'.$owner)
                ->where('live_hider.is_banned', false));
    }
}
