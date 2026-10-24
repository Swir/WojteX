<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Bounded admin-only account lookup for moderation beyond the recent-100 dashboard. */
final class AdminUserLookup
{
    public static function search(string $term): Builder
    {
        $term = trim($term);
        $users = DB::table('users');
        if (preg_match('/^#([1-9][0-9]{0,17})$/D', $term, $matches) === 1) {
            return $users->where('id', (int) $matches[1])->orderByDesc('id')->limit(20);
        }
        // Name or e-mail is visible ONLY to administrators through the admin middleware.
        // Treat %, _ and ! literally, so a wildcard cannot enumerate the user table.
        $pattern = PrivateMessageRecipientSearch::likePattern($term);
        return $users->where(static fn ($q) => $q
            ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereRaw("email LIKE ? ESCAPE '!'", [$pattern]))
            ->orderByDesc('id')->limit(20);
    }
}
