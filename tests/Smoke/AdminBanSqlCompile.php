<?php
/** Compile actual production UPDATE through Laravel SQLite/MySQL/MariaDB grammars; NO PDO. */
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AdminBanQuery;
use Illuminate\Support\Facades\DB;

$results=[];
foreach (['sqlite','mysql','mariadb'] as $driver) {
    DB::setDefaultConnection($driver);
    $connection=DB::connection();
    $grammar=$connection->getQueryGrammar();
    foreach ([[2,1],[2,3],[3,1],[1,1],[404,1]] as [$target,$actor]) {
        $query=AdminBanQuery::eligible($target,$actor);
        $values=AdminBanQuery::values(true,'2026-10-23 13:00:00');
        $results[$driver]["$target:$actor"]=[
            'sql'=>$grammar->compileUpdate($query,$values),
            'bindings'=>$grammar->prepareBindingsForUpdate($query->getRawBindings(),$values),
        ];
        $check=AdminBanQuery::eligible($target,$actor)->where(AdminBanQuery::banColumn(),true);
        $results[$driver]["$target:$actor"]['guard_sql']=$check->toSql();
        $results[$driver]["$target:$actor"]['guard_bindings']=$check->getBindings();
    }
}
echo json_encode($results,JSON_THROW_ON_ERROR);
