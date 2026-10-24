<?php
/** Compile real Laravel ranking authorization SQL without PDO. */
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\RankingsReadQuery;
use Illuminate\Support\Facades\DB;

if (DB::connection()->getDriverName() !== 'sqlite') throw new RuntimeException('Expected SQLite grammar');
$visible = [3];
$users = DB::table('users')->where('is_banned',false)->select('users.*')
    ->selectSub(DB::table('posts')->join('threads','threads.id','=','posts.thread_id')
        ->selectRaw('COUNT(*)')->whereColumn('posts.user_id','users.id')
        ->whereIn('threads.category_id',$visible),'posts_count')
    ->selectSub(DB::table('reactions')->join('posts','posts.id','=','reactions.post_id')
        ->join('threads','threads.id','=','posts.thread_id')
        ->selectRaw('COUNT(*)')->whereColumn('posts.user_id','users.id')
        ->whereIn('threads.category_id',$visible),'reactions_count');
$ranked = RankingsReadQuery::eligible(DB::query()->fromSub($users,'ranked'))
    ->select('ranked.*')
    ->selectRaw('(CASE WHEN posts_count > 100 THEN 100 ELSE posts_count END) * 2 + reactions_count * 10 AS score')
    ->orderByDesc('score')->orderBy('id')->limit(50);
// Separate MySQL grammar compile confirms no driver-specific raw SQL is needed.
$mysql = new \Illuminate\Database\MySqlConnection(static function () {throw new RuntimeException('PDO disabled');}, 'veyra_fake_mysql');
$my = RankingsReadQuery::eligible($mysql->table('users'));
if (!str_contains($my->toSql(), 'exists') || $my->getBindings()!==['rankings',true])
    throw new RuntimeException('MySQL rankings guard missing');
// A CURRENT locking read, not a repeatable-read snapshot SELECT, is the
// serialization point for the plugin toggle on InnoDB. This check compiles
// the real Laravel MySQL grammar without needing a PDO connection.
$mysqlLock = $mysql->table('plugin_states')->where('key','rankings')->lockForUpdate()->limit(1);
$sql = strtolower($mysqlLock->toSql());
if (!str_contains($sql, 'plugin_states') || !str_ends_with($sql, 'for update')
    || $mysqlLock->getBindings() !== ['rankings'])
    throw new RuntimeException('MySQL rankings plugin gate is not a row lock');
// SQLite intentionally has no FOR UPDATE; do not claim concurrent guarantees.
$sqliteLock = DB::table('plugin_states')->where('key','rankings')->lockForUpdate()->limit(1);
if (str_contains(strtolower($sqliteLock->toSql()), 'for update'))
    throw new RuntimeException('Unexpected SQLite FOR UPDATE support');
echo json_encode(['sql'=>$ranked->toSql(),'bindings'=>$ranked->getBindings()],JSON_THROW_ON_ERROR);
