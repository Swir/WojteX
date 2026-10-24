<?php

require_once __DIR__.'/../../app/Services/PrivateMessageAccess.php';

use App\Services\PrivateMessageAccess as Policy;

$count = 0;
$check = static function (bool $observed, bool $expected, string $case) use (&$count): void {
    if ($observed !== $expected) throw new RuntimeException('FAILED: '.$case);
    $count++;
};

$check(Policy::canSend(1, 2, false, false), true, 'member sends to member');
$check(Policy::canSend(1, 1, false, false), false, 'no self-send');
$check(Policy::canSend(1, 2, true, false), false, 'banned sender');
$check(Policy::canSend(1, 2, false, true), false, 'banned recipient');
$check(Policy::canSend(0, 2, false, false), false, 'anonymous sender');
$check(Policy::canView(1, 1, 2, false), true, 'sender may view');
$check(Policy::canView(2, 1, 2, false), true, 'recipient may view');
$check(Policy::canView(3, 1, 2, false), false, 'outsider cannot view, even administrator');
$check(Policy::canView(2, 1, 2, true), false, 'banned account cannot view');
$check(Policy::canMarkRead(2, 2, false), true, 'recipient may mark read');
$check(Policy::canMarkRead(1, 2, false), false, 'sender cannot mark read');
$check(Policy::canMarkRead(3, 2, false), false, 'third-party cannot mark read');
$check(Policy::canMarkRead(2, 2, true), false, 'banned recipient cannot mark read');

fwrite(STDOUT, "PASS: {$count} private-message policy assertions (no database/HTTP)\n");
