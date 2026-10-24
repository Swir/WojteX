<?php
/** Compile production reaction INSERT and DELETE through the real Laravel SQLite grammar. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ReactionToggleQuery;
use Illuminate\Support\Facades\DB;

$db=DB::connection();
if ($db->getDriverName() !== 'sqlite') throw new RuntimeException('Expected SQLite grammar');
$insert=ReactionToggleQuery::eligibleInsert(44,7,'2026-10-23 16:00:00');
$delete=ReactionToggleQuery::eligibleDelete(44,7);
$outer=DB::table('reactions');
echo json_encode([
    'insert'=>[
        'sql'=>$db->getQueryGrammar()->compileInsertOrIgnoreUsing($outer,
            ['post_id','user_id','created_at','updated_at'],$insert->toSql()),
        'bindings'=>$insert->getBindings(),
    ],
    'delete'=>[
        'sql'=>$db->getQueryGrammar()->compileDelete($delete),
        'bindings'=>$delete->getBindings(),
    ],
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
