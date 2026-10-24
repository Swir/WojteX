<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Authorize report moderation against the CURRENT DB actor, not a session
 * User snapshot. Guard the SELECT that includes confidential PM content and
 * the UPDATE changing a report with the same live account predicate.
 */
final class PrivateMessageReportAdminQuery
{
    public static function activeAdmin(int $actorId): Builder
    {
        return DB::table('users')
            ->where('id', $actorId)
            ->where('role', 'admin')
            ->where('is_banned', false);
    }

    public static function guarded(Builder $query, int $actorId): Builder
    {
        return $query->whereExists(static fn (Builder $check) => $check
            ->selectRaw('1')
            ->from('users as live_admin')
            ->where('live_admin.id', $actorId)
            ->where('live_admin.role', 'admin')
            ->where('live_admin.is_banned', false));
    }

    public static function reviewable(int $reportId, int $actorId): Builder
    {
        return self::guarded(DB::table('private_message_reports')->where('id', $reportId), $actorId);
    }
}
