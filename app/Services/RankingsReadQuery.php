<?php

namespace App\Services;


/** Rank data may only be selected while the module is enabled in persisted state. */
final class RankingsReadQuery
{
    public static function eligible($rankingQuery)
    {
        return $rankingQuery->whereExists(static fn ($query) => $query
            ->selectRaw('1')
            ->from('plugin_states as live_rankings')
            ->where('live_rankings.key', 'rankings')
            ->where('live_rankings.enabled', true));
    }
}
