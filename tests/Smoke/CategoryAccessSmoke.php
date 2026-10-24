<?php
/** Database-free assertions for private-category group policy. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
use App\Services\CategoryAccess;
function policyCheck(bool $condition, string $message): void {
    $GLOBALS['category_policy_checks'] = ($GLOBALS['category_policy_checks'] ?? 0) + 1;
    if (!$condition) { fwrite(STDERR, 'FAIL '.$message.PHP_EOL); exit(1); }
}
$categories = [
    1 => (object)['id'=>1, 'parent_id'=>null, 'is_private'=>false],
    2 => (object)['id'=>2, 'parent_id'=>null, 'is_private'=>true],
    3 => (object)['id'=>3, 'parent_id'=>2, 'is_private'=>false],
    4 => (object)['id'=>4, 'parent_id'=>2, 'is_private'=>true],
    5 => (object)['id'=>5, 'parent_id'=>777, 'is_private'=>false],
    6 => (object)['id'=>6, 'parent_id'=>6, 'is_private'=>false],
];
$allow = fn(int $id, array $grants, bool $member = true, string $ability = 'view')
    => CategoryAccess::allows($categories, $id, $grants, $member, $ability);
policyCheck($allow(1, [], false), 'public category for guest');
policyCheck(!$allow(1, [], false, 'create'), 'guest cannot write');
policyCheck($allow(1, [], true, 'reply'), 'existing public reply rights');
policyCheck(!$allow(2, []), 'no default private access');
policyCheck(!$allow(3, []), 'private ancestor blocks public child');
policyCheck(!$allow(4, [4=>['view'=>true]]), 'missing parent grant blocks private child');
$view = [2=>['view'=>true, 'create'=>false, 'reply'=>false]];
policyCheck($allow(2, $view), 'private group reader');
policyCheck(!$allow(2, $view, true, 'create'), 'view is not create');
policyCheck(!$allow(2, $view, true, 'reply'), 'view is not reply');
policyCheck($allow(3, $view, true, 'reply'), 'public child under authorized private parent');
$writer = [2=>['view'=>true, 'create'=>true, 'reply'=>true]];
policyCheck($allow(2, $writer, true, 'create'), 'private author');
policyCheck($allow(2, $writer, true, 'reply'), 'private respondent');
policyCheck($allow(4, $writer)===false, 'nested private child needs own grant');
$full = $writer + [4=>['view'=>true,'create'=>false,'reply'=>true]];
policyCheck($allow(4, $full, true, 'reply'), 'nested reply');
policyCheck(!$allow(4, $full, true, 'create'), 'nested create denied');
policyCheck(!$allow(2, $writer, false), 'guest cannot use member grants');
policyCheck(!$allow(5, []), 'missing parent fails closed');
policyCheck(!$allow(6, []), 'cycle fails closed');
policyCheck(!$allow(1, [], true, 'moderate'), 'unrecognized ability fails closed');
echo 'PASS: '.$GLOBALS['category_policy_checks'].' category group-policy assertions (read/write, ancestors, guests, cycles)'.PHP_EOL;
