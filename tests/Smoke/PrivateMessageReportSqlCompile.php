<?php
/** Compile real Laravel SQLite report INSERT without opening PHP PDO. */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PrivateMessageReportQuery as Reports;
use Illuminate\Support\Facades\DB;

$connection = DB::connection();
if ($connection->getDriverName() !== 'sqlite') {
    throw new RuntimeException('The smoke requires SQLite grammar');
}
$columns = ['message_id', 'reporter_id', 'reason', 'details', 'status', 'reviewed_by',
    'reviewed_at', 'created_at', 'updated_at'];
$compile = static function (int $viewer, int $message) use ($connection, $columns): array {
    $select = Reports::eligible($viewer, $message, 'spam', 'Evidence text', '2026-10-22 12:00:00');
    return [
        'sql' => $connection->getQueryGrammar()->compileInsertOrIgnoreUsing(
            DB::table('private_message_reports'), $columns, $select->toSql()),
        'bindings' => $select->getBindings(),
    ];
};
echo json_encode([
    'recipient' => $compile(2, 100),
    'sender' => $compile(1, 100),
    'stranger' => $compile(3, 100),
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
