<?php
/** Compile actual production UPDATE using Laravel SQLite grammar; no PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PostEditQuery;
use Illuminate\Support\Facades\DB;

$connection = DB::connection();
if ($connection->getDriverName() !== 'sqlite') throw new RuntimeException('Expected SQLite grammar');
$grammar = $connection->getQueryGrammar();
$values = ['body' => 'Updated body 2026', 'updated_at' => '2026-10-23 09:00:00'];
$statements = [];
foreach ([[88,7],[89,7],[89,9],[89,10],[88,404],[123,7]] as [$postId,$actorId]) {
    $query = PostEditQuery::eligible($postId,$actorId);
    $statements["$postId:$actorId"] = [
        'sql' => $grammar->compileUpdate($query, $values),
        'bindings' => $grammar->prepareBindingsForUpdate($query->getRawBindings(),$values),
    ];
}
echo json_encode($statements,JSON_THROW_ON_ERROR);
