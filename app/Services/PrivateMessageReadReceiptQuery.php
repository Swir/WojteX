<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Bind an inbound read receipt to the currently active recipient and their visible copy. */
final class PrivateMessageReadReceiptQuery
{
    public static function activeRecipient(int $viewerId): Builder
    {
        return DB::table('users')->where('id', $viewerId)->where('is_banned', false);
    }

    /**
     * One SQL UPDATE checks recipient ownership, copy visibility, unread status,
     * and the LIVE account ban/existence. This guards the mutation even if an
     * earlier session/preflight snapshot becomes stale. No SQLite row-lock claim.
     */
    public static function eligible(int $viewerId, int $messageId): Builder
    {
        return DB::table('private_messages')
            ->where('id', $messageId)
            ->where('recipient_id', $viewerId)
            ->whereNull('recipient_hidden_at')
            ->whereNull('read_at')
            ->whereExists(static fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('users as live_reader')
                ->whereColumn('live_reader.id', 'private_messages.recipient_id')
                ->where('live_reader.is_banned', false));
    }

    /** For an authorized duplicate PATCH, do not modify the original receipt time. */
    public static function visibleRead(int $viewerId, int $messageId): Builder
    {
        return DB::table('private_messages')->where('id', $messageId)
            ->where('recipient_id', $viewerId)
            ->whereNull('recipient_hidden_at')->whereNotNull('read_at');
    }
}
