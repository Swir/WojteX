<?php
/** Compile the ACTUAL guarded reset UPDATE for Laravel SQLite/MySQL/MariaDB, no PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app=require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PasswordResetWriteQuery;
use Illuminate\Support\Facades\DB;

$out=[];
foreach (['sqlite','mysql','mariadb'] as $driver) {
    DB::setDefaultConnection($driver);
    $query=PasswordResetWriteQuery::eligible(7);
    $update=[
        'password'=>'$2y$04$abcdefghijklmnopqrstuuabcdefghijklmnopqrstuvwxyabcdefghijklmn',
        'legacy_password_hash'=>null,
        'legacy_password_salt'=>null,
        'remember_token'=>'new-remember-token',
        'updated_at'=>'2026-10-24 00:15:00',
    ];
    $grammar=DB::connection()->getQueryGrammar();
    $out[$driver]=[
        'sql'=>$grammar->compileUpdate($query,$update),
        'bindings'=>$grammar->prepareBindingsForUpdate($query->getRawBindings(),$update),
    ];
}
echo json_encode($out, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
