<?php
/** Production Laravel grammar compilation only, without a PDO connection. */
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ShoutboxReadQuery;
use Illuminate\Support\Facades\DB;

if(DB::connection()->getDriverName()!=='sqlite') throw new RuntimeException('Expected SQLite grammar');
$first=ShoutboxReadQuery::eligible()->orderByDesc('id')->limit(30);
$after=ShoutboxReadQuery::eligible()->where('id','>',40)->orderBy('id')->limit(100);
$mysql=new \Illuminate\Database\MySqlConnection(static function(){
    throw new RuntimeException('Unexpected PDO MySQL connection');
},'veyra_fake_mysql');
$mysql->setTablePrefix('');
$mysqlQuery=$mysql->table('shouts')->whereExists(static fn($q)=>$q
    ->selectRaw('1')->from('plugin_states as live_shoutbox')
    ->where('live_shoutbox.key','shoutbox')->where('live_shoutbox.enabled',true));
if(!str_contains($mysqlQuery->toSql(),'exists') || $mysqlQuery->getBindings()!==['shoutbox',true])
    throw new RuntimeException('MySQL-compatible live plugin predicate missing');
echo json_encode([
    'initial'=>['sql'=>$first->toSql(),'bindings'=>$first->getBindings()],
    'after'=>['sql'=>$after->toSql(),'bindings'=>$after->getBindings()],
],JSON_THROW_ON_ERROR);
