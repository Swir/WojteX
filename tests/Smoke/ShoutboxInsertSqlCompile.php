<?php
/** Compile production guarded shout SQL using actual Laravel SQLite grammar. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ShoutboxWriteQuery;
use Illuminate\Support\Facades\DB;

$connection = DB::connection();
if ($connection->getDriverName() !== 'sqlite') throw new RuntimeException('Expected sqlite grammar');
$cols = ['user_id','body','created_at','updated_at'];
$queries = [];
foreach ([10, 11, 12, 999] as $userId) {
    $select = ShoutboxWriteQuery::eligible($userId, 'Guarded post '.$userId, '2026-10-23 02:10:00');
    $queries[(string) $userId] = [
        'sql' => $connection->getQueryGrammar()->compileInsertUsing(DB::table('shouts'),$cols,$select->toSql()),
        'bindings' => $select->getBindings(),
    ];
}
echo json_encode($queries, JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
