<?php
/** Compile production query-builder SQL without opening PHP PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app=require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PrivateMessageBlockToggleQuery as Guard;
use Illuminate\Support\Facades\DB;

$db=DB::connection();
if ($db->getDriverName()!=='sqlite') throw new RuntimeException('Requires SQLite grammar');
$select=Guard::eligibleInsert(7,11,'2026-10-23 19:30:00');
$target=DB::table('private_message_blocks');
$delete=Guard::eligibleDelete(7,11);
echo json_encode([
    'insert'=>[
        'sql'=>$db->getQueryGrammar()->compileInsertOrIgnoreUsing($target,
            ['blocker_id','blocked_id','created_at','updated_at'],$select->toSql()),
        'bindings'=>$select->getBindings(),
    ],
    'delete'=>[
        'sql'=>$db->getQueryGrammar()->compileDelete($delete),
        'bindings'=>$delete->getBindings(),
    ],
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
