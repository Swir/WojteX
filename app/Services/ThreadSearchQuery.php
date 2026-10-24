<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;

/**
 * Literal contains-search for visible forum threads and their post bodies.
 *
 * SQLite and MySQL both support a single-character LIKE ESCAPE expression.
 * Bind the escaped search term; never interpolate user text in SQL. The
 * caller supplies a query already restricted to viewable category IDs.
 */
final class ThreadSearchQuery
{
    public static function shouldSearch(?string $term): bool
    {
        // PHP empty("0") is true; do not turn a search for zero into a full feed.
        return $term !== null && trim($term) !== '';
    }

    public static function apply(Builder $threads, string $term): Builder
    {
        // Keep '%' and '_' as actual text; otherwise an innocent search for
        // "100%" unexpectedly enumerates *all* visible threads.
        // Escape '!' first, in a single pass, so a literal escape marker works.
        $pattern = '%'.strtr(trim($term), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

        return $threads->where(static fn (Builder $query) => $query
            ->whereRaw("t.title LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereExists(static fn (Builder $posts) => $posts
                ->selectRaw('1')->from('posts')
                ->whereColumn('posts.thread_id', 't.id')
                ->whereRaw("posts.body LIKE ? ESCAPE '!'", [$pattern])
            )
        );
    }
}
