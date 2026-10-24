<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Only the currently existing, unbanned owner may mutate their profile. */
final class ProfileUpdateQuery
{
    public static function eligible(int $actorId)
    {
        return DB::table('users')->where('id', $actorId)->where('is_banned', false);
    }
}
