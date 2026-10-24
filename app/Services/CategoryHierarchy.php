<?php

namespace App\Services;

/**
 * Validate an administrative parent reassignment before touching the category tree.
 * A JSON form may submit an ID as a numeric string even after Laravel's
 * "integer" validator: strict string-vs-int comparisons alone miss self-loops.
 */
final class CategoryHierarchy
{
    private static function positiveId(mixed $value): ?int
    {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || !preg_match('/\A[1-9][0-9]*\z/D', $value)) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : $id;
    }

    /**
     * @param callable(int):?object $lookup Direct database lookup of an ancestor
     *                             row with a parent_id field (null for root).
     */
    public static function allows(int $categoryId, mixed $requestedParent, callable $lookup): bool
    {
        if ($requestedParent === null) return true; // Explicitly move to root.
        $parent = self::positiveId($requestedParent);
        if ($parent === null) return false;
        $seen = [];
        while ($parent !== null) {
            if ($parent === $categoryId || isset($seen[$parent])) return false;
            $seen[$parent] = true;
            $row = $lookup($parent);
            if (!is_object($row) || !property_exists($row, 'parent_id')) return false;
            if ($row->parent_id === null) return true;
            $parent = self::positiveId($row->parent_id);
            if ($parent === null) return false; // Corrupt / dangling chain.
        }
        return true;
    }
}
