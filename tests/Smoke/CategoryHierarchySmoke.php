<?php
/** Database-free regression for an admin-provided numeric STRING parent_id. */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\CategoryHierarchy;

$assertions = 0;
function categoryTreeAssert(bool $condition, string $message): void {
    global $assertions;
    ++$assertions;
    if (!$condition) {
        fwrite(STDERR, 'FAIL: '.$message.PHP_EOL);
        exit(1);
    }
}
$rows = [
    1 => (object)['parent_id' => null],
    2 => (object)['parent_id' => '1'],
    3 => (object)['parent_id' => 2],
    4 => (object)['parent_id' => null],
    5 => (object)['parent_id' => '6'],
    6 => (object)['parent_id' => 5],
    7 => (object)['parent_id' => 404],
];
$lookup = static fn (int $id): ?object => $rows[$id] ?? null;
categoryTreeAssert(CategoryHierarchy::allows(1, null, $lookup), 'detaching to root');
categoryTreeAssert(CategoryHierarchy::allows(4, '1', $lookup), 'valid numeric string parent');
categoryTreeAssert(CategoryHierarchy::allows(4, 3, $lookup), 'valid ancestor chain');
categoryTreeAssert(!CategoryHierarchy::allows(1, '1', $lookup), 'string self-parent rejected');
categoryTreeAssert(!CategoryHierarchy::allows(1, 1, $lookup), 'int self-parent rejected');
categoryTreeAssert(!CategoryHierarchy::allows(1, '3', $lookup), 'string three-level descendant rejected');
categoryTreeAssert(!CategoryHierarchy::allows(2, '3', $lookup), 'int indirect descendant rejected');
categoryTreeAssert(!CategoryHierarchy::allows(4, '5', $lookup), 'legacy cycle rejected');
categoryTreeAssert(!CategoryHierarchy::allows(4, 7, $lookup), 'dangling ancestor rejected');
categoryTreeAssert(!CategoryHierarchy::allows(4, 404, $lookup), 'missing direct parent rejected');
categoryTreeAssert(!CategoryHierarchy::allows(4, '0', $lookup), 'zero rejected');
categoryTreeAssert(!CategoryHierarchy::allows(4, '7junk', $lookup), 'non-numeric parent rejected');
categoryTreeAssert(!CategoryHierarchy::allows(4, '-1', $lookup), 'negative parent rejected');
categoryTreeAssert(!CategoryHierarchy::allows(4, '01', $lookup), 'noncanonical parent rejected');
categoryTreeAssert(!CategoryHierarchy::allows(4, 1.0, $lookup), 'float parent rejected');
categoryTreeAssert(!CategoryHierarchy::allows(4, PHP_INT_MAX.'0', $lookup), 'overflow rejected');
$used = [];
categoryTreeAssert(CategoryHierarchy::allows(4, '3', static function (int $id) use ($lookup, &$used): ?object { $used[] = $id; return $lookup($id); }), 'deep allowed lookup');
categoryTreeAssert($used === [3, 2, 1], 'all ancestor checks and integer DB ids');
$used = [];
categoryTreeAssert(!CategoryHierarchy::allows(3, '3', static function (int $id) use (&$used): ?object { $used[] = $id; return null; }), 'reject before querying self-parent');
categoryTreeAssert($used === [], 'no query on self-parent');
$controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/AdminController.php');
categoryTreeAssert(str_contains($controller, 'CategoryHierarchy::allows($id, $data[\'parent_id\']'),
    'production category update must call the validated hierarchy policy');
categoryTreeAssert(!str_contains($controller, '$parent===$id'),
    'legacy string-vs-int strict comparison must not control category updates');
echo 'CategoryHierarchy standalone smoke PASS: '.$assertions.' assertions'.PHP_EOL;
