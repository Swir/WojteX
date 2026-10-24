<?php
/** Compile real Laravel INSERT...SELECT for Python SQLite verification (no PDO connection). */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ThreadCreateQuery;
use Illuminate\Support\Facades\DB;

$connection = DB::connection();
if ($connection->getDriverName() !== 'sqlite') throw new RuntimeException('Expect sqlite SQL grammar');
$cols = ['category_id','user_id','title','slug','created_at','updated_at'];
$rows = [];
foreach ([7,8,9,999] as $actorId) {
    $q = ThreadCreateQuery::eligible($actorId, 5, 'Literal thread '.$actorId,
        'literal-thread-'.$actorId, '2026-10-23 14:35:00');
    $rows[(string) $actorId] = [
        'sql' => $connection->getQueryGrammar()->compileInsertUsing(DB::table('threads'),$cols,$q->toSql()),
        'bindings' => $q->getBindings(),
    ];
}
echo json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
