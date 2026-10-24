<?php
// Router for PHP's local development server. Production uses public/index.php.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$target=realpath(__DIR__.'/'.$path);
if($path!=='/'&&$target&&str_starts_with($target,__DIR__.DIRECTORY_SEPARATOR)&&is_file($target)&&!str_ends_with($target,'.php'))return false;
require __DIR__.'/index.php';
