<?php
/** Contact blocking policy checks, running without a database or Laravel runtime. */
require __DIR__.'/../../app/Services/PrivateMessageContactPolicy.php';

use App\Services\PrivateMessageContactPolicy as Policy;

$checks = 0;
$assert = static function (bool $actual, bool $wanted, string $label) use (&$checks): void {
    if ($actual !== $wanted) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    ++$checks;
};
foreach ([0, -1] as $bad) {
    $assert(Policy::canBlock($bad, 2), false, 'invalid viewer');
    $assert(Policy::canBlock(2, $bad), false, 'invalid target');
}
$assert(Policy::canBlock(2, 2), false, 'self block rejected');
$assert(Policy::canBlock(2, 3), true, 'owner may block contact');
$assert(Policy::canMessage(false, false), true, 'unblocked peer pair may communicate');
$assert(Policy::canMessage(true, false), false, 'sender has blocked recipient');
$assert(Policy::canMessage(false, true), false, 'recipient has blocked sender');
$assert(Policy::canMessage(true, true), false, 'mutual block');
$assert(Policy::canMessage(false, false), true, 'unblock enables subsequent sending');
echo "PASS: {$checks} contact block policy assertions (PHP pure, not HTTP)\n";
