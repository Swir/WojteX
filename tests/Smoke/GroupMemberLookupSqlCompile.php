<?php
/** Compile the real Laravel group-account lookup without opening PHP PDO. */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\GroupMemberLookup;
use Illuminate\Support\Facades\DB;

if (DB::connection()->getDriverName() !== 'sqlite') {
    throw new RuntimeException('Expected SQLite grammar');
}
$cases = ['old' => 'Veteran Player', 'duplicate' => 'Echo', 'exact_id' => '#1',
    'percent' => '100%', 'underscore' => 'nick_', 'marker' => 'hello!', 'wide' => 'Filler'];
$result = [];
foreach ($cases as $key => $term) {
    $query = GroupMemberLookup::search($term)->select(['id', 'name', 'is_banned']);
    $result[$key] = ['sql' => $query->toSql(), 'bindings' => $query->getBindings()];
}
$counts = GroupMemberLookup::memberCounts()->toSql();
$result['member_counts'] = ['sql' => $counts, 'bindings' => GroupMemberLookup::memberCounts()->getBindings()];
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
