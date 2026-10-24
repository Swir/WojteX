<?php
/** Zero-DB validation of user-controlled LIKE wildcard escaping. */
require_once __DIR__.'/../../app/Services/PrivateMessageRecipientSearch.php';
use App\Services\PrivateMessageRecipientSearch as Search;
$cases = [
    ['Alice', '%Alice%'],
    [' % ', '%!%%'],
    ['_', '%!_%'],
    ['a_b', '%a!_b%'],
    ['20% off!', '%20!% off!!%'],
    ['!!', '%!!!!%'],
    ["x' OR 1=1", "%x' OR 1=1%"],
    ['  foo  bar ', '%foo  bar%'],
];
foreach ($cases as [$input, $expected]) {
    $actual = Search::likePattern($input);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: ".json_encode([$input, $actual, $expected]).PHP_EOL);
        exit(1);
    }
}
fwrite(STDOUT, 'PASS: '.count($cases).' literal LIKE escaping assertions (PHP, no DB/HTTP)'.PHP_EOL);
