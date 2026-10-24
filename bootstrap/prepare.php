<?php
// No framework required. This runs before session encryption on the first request.
$root=dirname(__DIR__);
foreach(['storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','storage/app/private','bootstrap/cache','database'] as $dir) {
    if(!is_dir($root.'/'.$dir)&&!mkdir($root.'/'.$dir,0750,true)&&!is_dir($root.'/'.$dir))throw new RuntimeException('Cannot create '.$dir);
}
$lock=fopen($root.'/storage/prepare.lock','c');
if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock installation preparation.');
try {
    if(!is_file($root.'/.env')) {
        $env=file_get_contents($root.'/.env.example');
        $env=preg_replace('/^APP_KEY=.*$/m','APP_KEY=base64:'.base64_encode(random_bytes(32)),$env);
        if(file_put_contents($root.'/.env',$env,LOCK_EX)===false)throw new RuntimeException('Cannot write .env; run php tools/prepare.php or copy .env.example manually.');
        chmod($root.'/.env',0600);
    }
    if(!is_file($root.'/database/database.sqlite')) {touch($root.'/database/database.sqlite');chmod($root.'/database/database.sqlite',0600);}
    if(!is_file($root.'/storage/install-token')&&!is_file($root.'/storage/installed.lock')) {
        file_put_contents($root.'/storage/install-token',bin2hex(random_bytes(24)),LOCK_EX);chmod($root.'/storage/install-token',0600);
    }
} finally {flock($lock,LOCK_UN);fclose($lock);}
