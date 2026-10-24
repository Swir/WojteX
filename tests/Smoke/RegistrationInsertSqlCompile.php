<?php
/** Compile the live registration gate through Laravel without opening PHP PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RegistrationInsertQuery;
use Illuminate\Support\Facades\DB;

$columns=['name','email','password','role','color','created_at','updated_at'];
$out=[];
foreach(['sqlite','mysql','mariadb'] as $driver) {
    DB::setDefaultConnection($driver);
    $connection=DB::connection();
    $select=RegistrationInsertQuery::eligible('New member','new@example.test',
        '$2y$test-password-hash-only', 'member','violet','2026-10-24 04:33:00');
    $out[$driver]=[
        'sql'=>$connection->getQueryGrammar()->compileInsertUsing(DB::table('users'),$columns,$select->toSql()),
        'bindings'=>$select->getBindings(),
    ];
}
echo json_encode($out,JSON_THROW_ON_ERROR);
