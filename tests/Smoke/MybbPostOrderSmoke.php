<?php
/** Test actual source-ID ordering, without pretending PHP PDO/MyBB imports ran. */
require __DIR__.'/../../vendor/autoload.php';

use App\Services\MybbPostOrder;

$checks = 0;
function expectOrder(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
}

// This is how the importer historically canonicalizes its fingerprint.
// It must stay unchanged to allow old snapshots of the same source to be
// recognized after the fix.
$legacy = [10 => 'second', 2 => 'starter', 100 => 'later', 9 => 'reply', 11 => 'next'];
ksort($legacy, SORT_STRING);
expectOrder(array_keys($legacy) === [10, 100, 11, 2, 9], 'Unexpected old fingerprint order');
expectOrder(reset($legacy) !== 'starter', 'Original import would not misorder the starter');

$sorted = MybbPostOrder::bySourceId($legacy);
expectOrder(array_keys($sorted) === [2, 9, 10, 11, 100], 'Post insert order not ascending numerically');
expectOrder(reset($sorted) === 'starter', 'Starter must be first in inserted thread');
expectOrder(array_keys($legacy) === [10, 100, 11, 2, 9], 'Must not mutate canonical fingerprint input');

// Verify a source ID above signed 32-bit range and a zero-leading-forbidden
// decimal boundary without relying on SORT_NUMERIC or floating point.
$large = ['4294967295' => 'max', '2147483648' => 'over-32bit', 15 => 'small', 9 => 'first'];
expectOrder(array_keys(MybbPostOrder::bySourceId($large)) === [9,15,2147483648,4294967295], 'Unsigned MyBB ID ordering');
expectOrder(MybbPostOrder::bySourceId([]) === [], 'Empty post set');
expectOrder(array_values(MybbPostOrder::bySourceId([7 => 'only'])) === ['only'], 'Single post');

// The actual importer must use the same sort for the INSERT loop, rather
// than merely importing this helper in isolation.
$importer = file_get_contents(__DIR__.'/../../app/Services/MybbImporter.php');
expectOrder(str_contains($importer, 'foreach (MybbPostOrder::bySourceId($prepared[\'posts\']) as $sourceId => $post)'), 'Production MyBB INSERT loop not wired to numeric post order');
expectOrder(str_contains($importer, 'ksort($$table, SORT_STRING);'), 'Canonical fingerprint sort changed (may break re-import idempotency)');
printf("PASS: %d production MyBB source-ID insertion-order assertions (no PDO/HTTP)\n", $checks);
