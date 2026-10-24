<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared contact-block SQL predicates; used by live delivery and discovery. */
final class PrivateMessageContactQuery
{
    /** Exclude users on either side of a unilateral PM block. */
    public static function availableRecipients(int $viewerId): Builder
    {
        return DB::table('users')->where('users.allow_private_messages', true)->whereNotExists(fn ($q) => $q
            ->selectRaw('1')->from('private_message_blocks')
            ->where(fn ($pair) => $pair
                ->where(fn ($a) => $a->where('blocker_id', $viewerId)
                    ->whereColumn('blocked_id', 'users.id'))
                ->orWhere(fn ($b) => $b->where('blocked_id', $viewerId)
                    ->whereColumn('blocker_id', 'users.id'))));
    }

    /** One SELECT for the guarded INSERT; requires exactly one eligible row. */
    public static function eligibleMessage(int $senderId, int $recipientId, string $body, string $timestamp): Builder
    {
        return DB::table('users')
            ->selectRaw('? AS sender_id, ? AS recipient_id, ? AS body, NULL AS read_at, ? AS created_at, ? AS updated_at',
                [$senderId, $recipientId, $body, $timestamp, $timestamp])
            ->where('users.id', $recipientId)
            ->where('users.id', '!=', $senderId)
            ->where('users.is_banned', false)
            ->where('users.allow_private_messages', true)
            // Membership may be banned after the initial PHP preflight.
            // Recheck both participants in this very INSERT...SELECT.
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('users as sender')
                ->where('sender.id', $senderId)->where('sender.is_banned', false))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('private_message_blocks')
                ->where(fn ($pair) => $pair
                    ->where(fn ($a) => $a->where('blocker_id', $senderId)->where('blocked_id', $recipientId))
                    ->orWhere(fn ($b) => $b->where('blocker_id', $recipientId)->where('blocked_id', $senderId))));
    }
}
