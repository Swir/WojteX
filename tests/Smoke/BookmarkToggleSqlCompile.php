<?php
/** Compile ACTUAL production bookmark mutation SQL without opening a DB connection. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app=require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\BookmarkToggleQuery;
use Illuminate\Support\Facades\DB;

$db=DB::connection();
if ($db->getDriverName()!=='sqlite') throw new RuntimeException('Expected sqlite grammar');
$insert=BookmarkToggleQuery::eligibleInsert(44,7,'2026-10-23 18:00:00');
$delete=BookmarkToggleQuery::eligibleDelete(44,7);
$target=DB::table('bookmarks');
echo json_encode([
    'insert'=>[
        'sql'=>$db->getQueryGrammar()->compileInsertOrIgnoreUsing($target,
            ['thread_id','user_id','created_at','updated_at'],$insert->toSql()),
        'bindings'=>$insert->getBindings(),
    ],
    'delete'=>[
        'sql'=>$db->getQueryGrammar()->compileDelete($delete),
        'bindings'=>$delete->getBindings(),
    ],
],JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
