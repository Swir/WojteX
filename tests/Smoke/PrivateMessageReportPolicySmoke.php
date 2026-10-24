<?php
/** Standalone policy checks: these do not exercise Laravel HTTP or PDO. */
require __DIR__.'/../../app/Services/PrivateMessageReportPolicy.php';

use App\Services\PrivateMessageReportPolicy as Policy;

$n = 0;
$check = static function (bool $actual, bool $want, string $label) use (&$n): void {
    if ($actual !== $want) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
    ++$n;
};
$check(Policy::canReport(11, 11, false), true, 'receiver can report visible received message');
$check(Policy::canReport(11, 12, false), false, 'sender or stranger cannot report');
$check(Policy::canReport(11, 11, true), false, 'hidden incoming copy cannot report');
$check(Policy::canReport(0, 0, false), false, 'invalid account cannot report');
foreach (['spam', 'harassment', 'threat', 'other'] as $reason) {
    $check(Policy::reasonAllowed($reason), true, 'allowed reason '.$reason);
}
foreach (['', 'unknown', 'admin', 'reporter'] as $reason) {
    $check(Policy::reasonAllowed($reason), false, 'rejected reason '.$reason);
}
foreach (['open', 'resolved', 'dismissed'] as $status) {
    $check(Policy::statusAllowed($status), true, 'allowed status '.$status);
}
foreach (['pending', 'deleted', ''] as $status) {
    $check(Policy::statusAllowed($status), false, 'rejected status '.$status);
}
echo "PASS: {$n} private-message report policy assertions (PHP pure, not HTTP)\n";
