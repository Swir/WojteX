<?php

namespace App\Services;

/**
 * Small, deterministic policy engine. Group grants can expose a private forum
 * to members without giving them moderator powers. Private ancestors must
 * each be readable; missing ancestors and cycles fail closed.
 *
 * Inputs are trusted database rows, not client-supplied permissions.
 */
final class CategoryAccess
{
    /**
     * Compute permissions for a full already-loaded category tree in O(C)
     * rather than walking its parent chain for EACH category (O(C²) on deep
     * imports). Missing parents and cycles fail closed, even for public rooms.
     * This is a read-time policy planner, not a cache across requests: revoked
     * grants are loaded afresh by the calling service.
     *
     * @param array<int, object> $categories Categories keyed by numeric id.
     * @param array<int, array{view?:bool,create?:bool,reply?:bool}> $grants
     * @return array<int, array{view:bool,create:bool,reply:bool}>
     */
    public static function accessMap(array $categories, array $grants, bool $member): array
    {
        $resolved = [];
        foreach ($categories as $startingId => $_) {
            $startingId = (int) $startingId;
            if (isset($resolved[$startingId])) continue;
            $path = [];
            $visiting = [];
            $currentId = $startingId;
            while ($currentId !== 0 && isset($categories[$currentId])
                && !isset($resolved[$currentId]) && !isset($visiting[$currentId])) {
                $visiting[$currentId] = true;
                $path[] = $currentId;
                $currentId = (int) ($categories[$currentId]->parent_id ?? 0);
            }
            // A missing ancestor or a cycle poisons ALL descendants of it.
            // Only the actual root (parent_id=null/0) is unconditionally visible.
            $parentVisible = $currentId === 0
                || ($resolved[$currentId]['view'] ?? false);
            for ($index = count($path) - 1; $index >= 0; --$index) {
                $id = $path[$index];
                $private = (bool) $categories[$id]->is_private;
                $visible = $parentVisible && (!$private
                    || ($member && (bool) ($grants[$id]['view'] ?? false)));
                $resolved[$id] = [
                    'view' => $visible,
                    'create' => $visible && $member && (!$private || (bool) ($grants[$id]['create'] ?? false)),
                    'reply' => $visible && $member && (!$private || (bool) ($grants[$id]['reply'] ?? false)),
                ];
                $parentVisible = $visible;
            }
        }
        return $resolved;
    }

    /**
     * @param array<int, object> $categories Categories keyed by numeric id.
     * @param array<int, array{view:bool,create:bool,reply:bool}> $grants
     */
    public static function allows(array $categories, int $id, array $grants, bool $member, string $ability = 'view'): bool
    {
        if (!in_array($ability, ['view', 'create', 'reply'], true) || !isset($categories[$id])) {
            return false;
        }
        if (!$member && $ability !== 'view') return false;

        $target = $categories[$id];
        $seen = [];
        $parent = $target;
        while ($parent !== null) {
            $currentId = (int) $parent->id;
            if (isset($seen[$currentId])) return false;
            $seen[$currentId] = true;
            if ((bool) $parent->is_private && (!$member || !($grants[$currentId]['view'] ?? false))) return false;
            $parentId = (int) ($parent->parent_id ?? 0);
            if ($parentId === 0) break;
            if (!isset($categories[$parentId])) return false;
            $parent = $categories[$parentId];
        }

        if ($ability === 'view') return true;
        // Public forums retain the alpha.2 write defaults for logged-in users.
        // Private rooms require an explicit ability as well as their view grant.
        return !(bool) $target->is_private || (bool) ($grants[$id][$ability] ?? false);
    }
}
