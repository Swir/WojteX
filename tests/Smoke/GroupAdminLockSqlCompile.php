<?php
/** Compile the actual live-actor-lock query for supported grammars; no PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
$out = [];
foreach (['sqlite', 'mysql', 'mariadb'] as $driver) {
    DB::setDefaultConnection($driver);
    $query = DB::table('users')->where('id', 1)->lockForUpdate();
    $out[$driver] = ['sql' => $query->toSql(), 'bindings' => $query->getBindings()];
}
echo json_encode($out, JSON_THROW_ON_ERROR);
