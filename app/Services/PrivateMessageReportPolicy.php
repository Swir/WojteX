<?php

namespace App\Services;

/** An abuse report exposes only a specifically reported inbound PM to the forum administrator. */
final class PrivateMessageReportPolicy
{
    public const REASONS = ['spam', 'harassment', 'threat', 'other'];
    public const STATUSES = ['open', 'resolved', 'dismissed'];

    public static function canReport(int $viewerId, int $recipientId, bool $recipientHidden): bool
    {
        return $viewerId > 0 && $viewerId === $recipientId && !$recipientHidden;
    }

    public static function reasonAllowed(string $reason): bool
    {
        return in_array($reason, self::REASONS, true);
    }

    public static function statusAllowed(string $status): bool
    {
        return in_array($status, self::STATUSES, true);
    }
}
