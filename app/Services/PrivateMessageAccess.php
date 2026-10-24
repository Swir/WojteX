<?php

namespace App\Services;

/**
 * Participant-only mailbox policy. Staff status never overrides privacy.
 * Pure policy for testing independently of the database adapter.
 */
final class PrivateMessageAccess
{
    public static function canSend(int $senderId, int $recipientId, bool $senderBanned, bool $recipientBanned): bool
    {
        return $senderId > 0 && $recipientId > 0 && $senderId !== $recipientId
            && !$senderBanned && !$recipientBanned;
    }

    public static function canView(int $viewerId, int $senderId, int $recipientId, bool $viewerBanned): bool
    {
        return $viewerId > 0 && !$viewerBanned && ($viewerId === $senderId || $viewerId === $recipientId);
    }

    /** A participant may hide only their own view of a message. */
    public static function canHide(int $viewerId, int $senderId, int $recipientId, bool $viewerBanned): bool
    {
        return self::canView($viewerId, $senderId, $recipientId, $viewerBanned);
    }

    /** Independently hidden copies must never reappear in paginated mailboxes. */
    public static function visibleInMailbox(
        int $viewerId, int $senderId, int $recipientId,
        bool $senderHidden, bool $recipientHidden, bool $viewerBanned
    ): bool {
        return self::canView($viewerId, $senderId, $recipientId, $viewerBanned)
            && ($viewerId === $senderId ? !$senderHidden : !$recipientHidden);
    }

    public static function canMarkRead(int $viewerId, int $recipientId, bool $viewerBanned): bool
    {
        return $viewerId > 0 && !$viewerBanned && $viewerId === $recipientId;
    }
}
