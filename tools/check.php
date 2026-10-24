<?php
// CLI preflight; no credentials are read or printed.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$checks = ['PHP >= 8.3' => PHP_VERSION_ID >= 80300];
foreach (['ctype','curl','dom','fileinfo','filter','hash','mbstring','openssl','pcre','pdo','session','tokenizer','xml'] as $extension) {
    $checks['ext-'.$extension] = extension_loaded($extension);
}
$checks['PDO SQLite lub PDO MySQL'] = extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql');
$checks['Argon2id'] = in_array('argon2id', password_algos(), true);
$checks['Zależności vendor'] = is_file(dirname(__DIR__).'/vendor/autoload.php');
echo 'WojteX Forum — PHP '.PHP_VERSION."\n";
foreach ($checks as $label => $passed) echo ($passed ? '[OK] ' : '[BRAK] ').$label."\n";
echo "Domyślna instalacja używa PDO SQLite. Dla MySQL skonfiguruj .env przed instalacją.\n";
exit(in_array(false, $checks, true) ? 1 : 0);
