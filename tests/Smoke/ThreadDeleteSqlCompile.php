<?php
/** Produce real Laravel SQLite grammar for guarded thread DELETE, without PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ThreadDeleteQuery;
use Illuminate\Support\Facades\DB;

$connection=DB::connection();
if ($connection->getDriverName() !== 'sqlite') throw new RuntimeException('Expected SQLite query grammar');
$statements=[];
foreach ([[30,1],[30,2],[30,3],[30,4],[31,2],[999,2]] as [$threadId,$actorId]) {
    $q=ThreadDeleteQuery::eligible($threadId,$actorId);
    $statements[$threadId.':'.$actorId]=[
        'sql'=>$connection->getQueryGrammar()->compileDelete($q),
        'bindings'=>$q->getBindings(),
    ];
}
echo json_encode($statements,JSON_THROW_ON_ERROR);
