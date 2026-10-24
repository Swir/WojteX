<?php
/** Compile actual production conditional DELETE with Laravel grammars, no PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app=require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PostDeleteQuery;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Facades\DB;

$connection=DB::connection();
if ($connection->getDriverName()!=='sqlite') throw new RuntimeException('Expected test SQLite grammar');
$sqlite=$connection->getQueryGrammar();
$mysql=new MySqlGrammar($connection);
$queries=[];
foreach ([[88,7,33,87],[88,8,33,87],[88,9,33,87],[88,10,33,87],[88,404,33,87],
          [87,9,33,87],[88,9,33,88],[89,7,33,87],[90,7,34,90],[123,7,33,87]] as $args) {
    $q=PostDeleteQuery::eligible(...$args);
    $queries[implode(':',$args)]=[
        'sql'=>$sqlite->compileDelete($q),
        'mysql_sql'=>$mysql->compileDelete($q),
        'bindings'=>$q->getBindings(),
    ];
}
echo json_encode($queries,JSON_THROW_ON_ERROR);
