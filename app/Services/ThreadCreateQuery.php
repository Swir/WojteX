<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Evaluate the creator's CURRENT account/ban state inside the thread INSERT.
 * A request-bound User can become stale between category authorization and
 * persistence. This is a single-statement ban guard, not a promise of global
 * serialization or an atomic re-evaluation of category/group permissions.
 */
final class ThreadCreateQuery
{
    /** @return \Illuminate\Database\Query\Builder */
    public static function eligible(int $actorId, int $categoryId, string $title, string $slug, string $timestamp)
    {
        return DB::table('users')
            ->where('users.id', $actorId)
            ->where('users.is_banned', false)
            ->selectRaw(
                '? AS category_id, users.id AS user_id, ? AS title, ? AS slug, ? AS created_at, ? AS updated_at',
                [$categoryId, $title, $slug, $timestamp, $timestamp]
            );
    }
}
