<?php
/** Standalone policy tests, not Laravel HTTP/database tests. */
require_once __DIR__.'/../../app/Services/PrivateMessageAccess.php';
use App\Services\PrivateMessageAccess as Access;
$count = 0;
$check = static function (bool $ok, string $note) use (&$count): void {
    if (!$ok) {fwrite(STDERR, "FAIL: {$note}\n"); exit(1);}
    $count++;
};
$check(Access::canHide(2, 2, 3, false), 'sender may hide');
$check(Access::canHide(3, 2, 3, false), 'recipient may hide');
$check(!Access::canHide(4, 2, 3, false), 'stranger may not hide');
$check(!Access::canHide(2, 2, 3, true), 'banned sender may not hide');
$check(!Access::canHide(0, 2, 3, false), 'guest may not hide');
$check(Access::visibleInMailbox(2, 2, 3, false, false, false), 'sender sees original');
$check(Access::visibleInMailbox(3, 2, 3, false, false, false), 'recipient sees original');
$check(!Access::visibleInMailbox(2, 2, 3, true, false, false), 'hidden sender copy invisible');
$check(Access::visibleInMailbox(3, 2, 3, true, false, false), 'recipient sees copy when sender hides');
$check(!Access::visibleInMailbox(3, 2, 3, false, true, false), 'hidden recipient copy invisible');
$check(Access::visibleInMailbox(2, 2, 3, false, true, false), 'sender sees copy when recipient hides');
$check(!Access::visibleInMailbox(2, 2, 3, true, true, false), 'both hidden invisible');
$check(!Access::visibleInMailbox(3, 2, 3, true, true, false), 'both hidden recipient invisible');
$check(!Access::visibleInMailbox(4, 2, 3, false, false, false), 'unrelated viewer never sees copy');
$check(!Access::visibleInMailbox(2, 2, 3, false, false, true), 'banned participant denied');
$check(Access::canMarkRead(3, 3, false) && !Access::canMarkRead(2, 3, false), 'only recipient reads');
echo "PASS: {$count} participant-visibility policy assertions (no DB/HTTP)\n";
