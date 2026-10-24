<?php

namespace App\Services;

/** SQLite/MySQL compatible escaping for a contains-LIKE search. */
final class PrivateMessageRecipientSearch
{
    public static function likePattern(string $name): string
    {
        // strtr uses single-pass replacement: escape marker before wildcards.
        return '%'.strtr(trim($name), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
    }
}
