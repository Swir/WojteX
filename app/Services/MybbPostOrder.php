<?php

namespace App\Services;

/**
 * MyBB pid values are unsigned decimal source IDs. A lexical ksort() is
 * deliberately used by the importer's canonical fingerprint, but must NOT
 * determine the physical insertion order of posts: "10" sorts before "2".
 *
 * Compare decimal lengths and bytes instead of using floats or a host-width
 * integer cast (the MyBB export accepts IDs up to 4294967295).
 */
final class MybbPostOrder
{
    /** @template T @param array<int|string, T> $posts @return array<int|string, T> */
    public static function bySourceId(array $posts): array
    {
        uksort($posts, static function (int|string $a, int|string $b): int {
            $first = (string) $a;
            $second = (string) $b;
            return strlen($first) <=> strlen($second) ?: strcmp($first, $second);
        });
        return $posts;
    }
}
