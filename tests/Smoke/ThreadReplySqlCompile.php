<?php
/** Compile the REAL Laravel query using SQLite grammar, without opening PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ThreadReplyQuery;
use Illuminate\Support\Facades\DB;

$db=DB::connection();
if($db->getDriverName()!=='sqlite') throw new RuntimeException('Expected SQLite grammar');
$cols=['thread_id','user_id','body','body_format','created_at','updated_at'];
$select=ThreadReplyQuery::eligible(7,42,'A real protected reply','2026-10-23 15:00:00');
echo json_encode([
  'sql'=>$db->getQueryGrammar()->compileInsertUsing(DB::table('posts'),$cols,$select->toSql()),
  'bindings'=>$select->getBindings(),
],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
