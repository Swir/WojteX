<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Shared live-account predicate and credential fields for password changes and recovery. */
final class PasswordResetWriteQuery
{
    /** @return \Illuminate\Database\Query\Builder */
    public static function eligible(int $userId)
    {
        return DB::table('users')
            ->where('id', $userId)
            ->where('is_banned', false);
    }

    public static function values(#[\SensitiveParameter] string $hash): array
    {
        return [
            'password' => $hash,
            'legacy_password_hash' => null,
            'legacy_password_salt' => null,
            'remember_token' => Str::random(60),
            'updated_at' => now()->toDateTimeString(),
        ];
    }
}
