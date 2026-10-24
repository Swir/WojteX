<?php
/** Actual Laravel Query Builder/SQLite grammar for guarded shout DELETE. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ShoutboxDeleteQuery;
use Illuminate\Support\Facades\DB;

$connection = DB::connection();
if ($connection->getDriverName() !== 'sqlite') throw new RuntimeException('Expected sqlite grammar');
$queries = [];
foreach ([[20, 1], [20, 2], [20, 3], [20, 4], [20, 99]] as [$shoutId, $actorId]) {
    $query = ShoutboxDeleteQuery::eligible($shoutId, $actorId);
    $queries[(string) $actorId] = [
        'sql' => $connection->getQueryGrammar()->compileDelete($query),
        'bindings' => $query->getBindings(),
    ];
}
echo json_encode($queries, JSON_THROW_ON_ERROR);
