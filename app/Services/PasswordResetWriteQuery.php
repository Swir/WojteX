<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * The password broker validates the token, but it does not enforce WojteX's
 * account-ban rule at the instant it persists a new password. The callback
 * must recheck the CURRENT user row in the same UPDATE that rotates secrets.
 */
final class PasswordResetWriteQuery
{
    /** @return \Illuminate\Database\Query\Builder */
    public static function eligible(int $userId)
    {
        return DB::table('users')
            ->where('id', $userId)
            ->where('is_banned', false);
    }
}
