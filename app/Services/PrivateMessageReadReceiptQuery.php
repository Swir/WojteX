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

    public static function received(int $viewerId): Builder
    {
        return DB::table('private_messages')->where('recipient_id', $viewerId)->whereNull('recipient_hidden_at');
    }

    public static function unread(int $viewerId): Builder
    {
        return self::received($viewerId)->whereNull('read_at');
    }

    public static function eligible(int $viewerId, int $messageId): Builder
    {
        return self::eligibleUnread($viewerId)->where('id', $messageId);
    }

    public static function eligibleThrough(int $viewerId, int $throughId): Builder
    {
        return self::eligibleUnread($viewerId)->where('id', '<=', $throughId);
    }

    /**
     * One SQL UPDATE checks recipient ownership, copy visibility, unread status,
     * and the LIVE account ban/existence. This guards the mutation even if an
     * earlier session/preflight snapshot becomes stale. No SQLite row-lock claim.
     */
    private static function eligibleUnread(int $viewerId): Builder
    {
        return self::unread($viewerId)
            ->whereExists(static fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('users as live_reader')
                ->whereColumn('live_reader.id', 'private_messages.recipient_id')
                ->where('live_reader.is_banned', false));
    }

    /** For an authorized duplicate PATCH, do not modify the original receipt time. */
    public static function visibleRead(int $viewerId, int $messageId): Builder
    {
        return self::received($viewerId)->where('id', $messageId)->whereNotNull('read_at');
    }
}
