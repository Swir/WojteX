<?php
/** Compile production Laravel INSERT..SELECT using SQLite grammar WITHOUT PHP PDO. */
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use App\Services\AttachmentInsertQuery;
use Illuminate\Support\Facades\DB;
$db=DB::connection();
if($db->getDriverName()!=='sqlite')throw new RuntimeException('Use SQLite SQL grammar only');
$cols=['post_id','user_id','path','name','mime','size','created_at','updated_at'];
$q=AttachmentInsertQuery::eligible(5,42,7,'attachments/test.txt','test.txt','text/plain',42,'2026-10-23 20:00:00');
echo json_encode([
    'sql'=>$db->getQueryGrammar()->compileInsertUsing(DB::table('attachments'),$cols,$q->toSql()),
    'bindings'=>$q->getBindings(),
],JSON_THROW_ON_ERROR);
