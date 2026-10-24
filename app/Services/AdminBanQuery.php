<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Guard a ban against BOTH live account rows rather than trusting cached
 * middleware/target state. One SQL UPDATE, no global serialization claim.
 * MySQL uses a self-join because a subquery on the table being updated can
 * trigger ER_UPDATE_TABLE_USED (1093); SQLite/PostgreSQL use EXISTS.
 */
final class AdminBanQuery
{
    public static function eligible(int $targetId, int $actorId)
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return DB::table('users as target')
                ->join('users as current_admin', static fn ($join) => $join
                    ->where('current_admin.id', $actorId)
                    ->where('current_admin.role', 'admin')
                    ->where('current_admin.is_banned', false))
                ->where('target.id', $targetId)
                ->where('target.id', '!=', $actorId)
                ->where('target.role', '!=', 'admin');
        }

        return DB::table('users')
            ->where('id', $targetId)
            ->where('id', '!=', $actorId)
            ->where('role', '!=', 'admin')
            ->whereExists(static fn ($query) => $query->selectRaw('1')
                ->from('users as current_admin')
                ->where('current_admin.id', $actorId)
                ->where('current_admin.role', 'admin')
                ->where('current_admin.is_banned', false));
    }

    /** Avoid ambiguous SET columns on MySQL's two-users UPDATE join. */
    public static function values(bool $banned, mixed $time): array
    {
        $prefix = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'target.' : '';
        return [$prefix.'is_banned' => $banned, $prefix.'updated_at' => $time];
    }

    public static function banColumn(): string
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'target.is_banned' : 'is_banned';
    }
}
