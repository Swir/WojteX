<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Bounded staff-only lookup independent of the admin dashboard's newest 100 accounts. */
final class GroupMemberLookup
{
    public static function search(string $term): Builder
    {
        $term = trim($term);
        $users = DB::table('users');
        // Prefix # requests an exact, stable account ID; plain digits remain
        // a display-name search. No interpolation of user input into SQL.
        if (preg_match('/^#([1-9][0-9]{0,17})$/D', $term, $matches) === 1) {
            return $users->where('id', (int) $matches[1])->orderBy('id')->limit(20);
        }
        return $users->whereRaw("name LIKE ? ESCAPE '!'", [PrivateMessageRecipientSearch::likePattern($term)])
            ->orderBy('name')->orderBy('id')->limit(20);
    }

    /** Count ALL members, never only accounts visible on the first admin page. */
    public static function memberCounts(): Builder
    {
        return DB::table('user_group_members')->select('group_id')
            ->selectRaw('COUNT(*) as member_count')->groupBy('group_id');
    }
}
