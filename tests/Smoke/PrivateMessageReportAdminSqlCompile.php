<?php
/** Compile the ACTUAL guarded report review/list statements. No PHP PDO execution. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PrivateMessageReportAdminQuery as Review;
use Illuminate\Support\Facades\DB;

$results = [];
foreach (['sqlite', 'mysql', 'mariadb'] as $driver) {
    DB::setDefaultConnection($driver);
    $grammar = DB::connection()->getQueryGrammar();
    foreach ([1, 2] as $actorId) {
        $query = Review::reviewable(42, $actorId)->where('status', '!=', 'resolved');
        $values = [
            'status' => 'resolved', 'reviewed_by' => $actorId,
            'reviewed_at' => '2026-10-23 15:00:00', 'updated_at' => '2026-10-23 15:00:00',
        ];
        $view = Review::guarded(DB::table('private_message_reports as report')
            ->where('report.status', 'open'), $actorId);
        $same = Review::reviewable(42, $actorId)->where('status', 'resolved');
        $results[$driver]["actor_$actorId"] = [
            'update_sql' => $grammar->compileUpdate($query, $values),
            'update_bindings' => $grammar->prepareBindingsForUpdate($query->getRawBindings(), $values),
            'list_sql' => $view->toSql(), 'list_bindings' => $view->getBindings(),
            'same_sql' => $same->toSql(), 'same_bindings' => $same->getBindings(),
        ];
    }
}
echo json_encode($results, JSON_THROW_ON_ERROR);
