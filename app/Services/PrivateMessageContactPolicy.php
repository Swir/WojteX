<?php

namespace App\Services;

/** User-controlled PM blocking. Staff roles never override a block. */
final class PrivateMessageContactPolicy
{
    public static function canBlock(int $viewerId, int $targetId): bool
    {
        return $viewerId > 0 && $targetId > 0 && $viewerId !== $targetId;
    }

    /** Either participant may unilaterally stop future PMs in both directions. */
    public static function canMessage(bool $senderBlockedRecipient, bool $recipientBlockedSender): bool
    {
        return !$senderBlockedRecipient && !$recipientBlockedSender;
    }
}
