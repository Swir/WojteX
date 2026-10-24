<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Never serve persisted shout content when the module was switched off after
 * the initial preflight. Keep the live plugin check inside the row SELECT.
 * This is one-statement eligibility, not global concurrent-read serialization.
 */
final class ShoutboxReadQuery
{
    public static function eligible()
    {
        return DB::table('shouts')
            ->whereExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('plugin_states as live_shoutbox')
                ->where('live_shoutbox.key', 'shoutbox')
                ->where('live_shoutbox.enabled', true));
    }
}
