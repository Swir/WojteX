<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Treat an administrator's registration switch as a write-time policy, not a
 * potentially stale settings snapshot from the start of an HTTP request.
 *
 * The installer historically relies on an absent registration_open row to
 * mean true. If that row exists, only the canonical JSON boolean `true` may
 * enable registration. An absent or uncommitted installation never registers.
 * This protects the INSERT's execution-time DB snapshot. It does not claim
 * serializable behavior for a registration concurrent with the same statement.
 */
final class RegistrationInsertQuery
{
    /** @return \Illuminate\Database\Query\Builder */
    public static function eligible(string $name, string $email, string $hashedPassword,
        string $role, string $color, string $timestamp)
    {
        return DB::table('settings')
            ->where('key', 'setup_complete')
            ->where('value', 'true')
            ->whereNotExists(static fn ($q) => $q
                ->selectRaw('1')
                ->from('settings as registration_flag')
                ->where('registration_flag.key', 'registration_open')
                ->where('registration_flag.value', '<>', 'true'))
            ->selectRaw(
                '? AS name, ? AS email, ? AS password, ? AS role, ? AS color, ? AS created_at, ? AS updated_at',
                [$name, $email, $hashedPassword, $role, $color, $timestamp, $timestamp]
            );
    }
}
