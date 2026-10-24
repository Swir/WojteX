<?php
require dirname(__DIR__).'/bootstrap/prepare.php';
echo "WojteX Forum — środowisko przygotowane.\n";
$token=dirname(__DIR__).'/storage/install-token';
if(is_file($token))echo "Kod instalacyjny: ".trim(file_get_contents($token))."\nOtwórz /install i utwórz konto administratora.\n";
else echo "Forum zostało już zainstalowane.\n";
