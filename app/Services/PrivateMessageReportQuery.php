<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Guard a PM abuse report against revoked identity and hidden/deleted inbox copies. */
final class PrivateMessageReportQuery
{
    /** Recheck the account from storage, not the request's potentially stale User. */
    public static function activeReporter(int $viewerId): Builder
    {
        return DB::table('users')->where('id', $viewerId)->where('is_banned', false);
    }

    /** One permission predicate for the actual INSERT and the duplicate/no-op path. */
    public static function visible(int $viewerId, int $messageId): Builder
    {
        return DB::table('private_messages')
            ->where('id', $messageId)
            ->where('recipient_id', $viewerId)
            ->whereNull('recipient_hidden_at')
            ->whereExists(static fn (Builder $query) => $query
                ->selectRaw('1')
                ->from('users as live_reporter')
                ->whereColumn('live_reporter.id', 'private_messages.recipient_id')
                ->where('live_reporter.is_banned', false));
    }

    public static function eligible(int $viewerId, int $messageId, string $reason,
        ?string $details, string $timestamp): Builder
    {
        return self::visible($viewerId, $messageId)
            ->selectRaw("id AS message_id, ? AS reporter_id, ? AS reason, ? AS details, 'open' AS status, NULL AS reviewed_by, NULL AS reviewed_at, ? AS created_at, ? AS updated_at", [
                $viewerId, $reason, $details, $timestamp, $timestamp,
            ]);
    }
}
