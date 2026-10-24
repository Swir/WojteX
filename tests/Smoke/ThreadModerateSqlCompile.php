<?php
/** Compile actual guarded UPDATE with Laravel SQLite grammar, without PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app=require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ThreadModerateQuery;
use Illuminate\Support\Facades\DB;

$connection=DB::connection();
if ($connection->getDriverName() !== 'sqlite') throw new RuntimeException('Expected SQLite grammar');
$grammar=$connection->getQueryGrammar();
$values=['is_locked'=>1,'is_pinned'=>1];
$result=[];
foreach ([[44,7],[44,8],[44,9],[45,9],[999,7],[44,404]] as [$threadId,$actorId]) {
    $q=ThreadModerateQuery::eligible($threadId,$actorId);
    $result["$threadId:$actorId"]=[
        'sql'=>$grammar->compileUpdate($q,$values),
        'bindings'=>$grammar->prepareBindingsForUpdate($q->getRawBindings(),$values),
    ];
}
echo json_encode($result,JSON_THROW_ON_ERROR);
