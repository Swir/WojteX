<?php
/** Compile production Laravel Query Builder moderation lookup; no PHP PDO required. */
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AdminUserLookup;
use Illuminate\Support\Facades\DB;

if (DB::connection()->getDriverName() !== 'sqlite') throw new RuntimeException('SQLite grammar required');
$terms = ['older' => 'Veteran', 'email' => '@archived.example', 'duplicates' => 'Echo',
    'exact_id' => '#1', 'percent' => '100%', 'underscore' => 'nick_',
    'escape' => 'hello!', 'many' => 'Filler'];
$compiled = [];
foreach ($terms as $label => $term) {
    $builder = AdminUserLookup::search($term)->select(['id', 'name', 'email', 'role', 'is_banned', 'created_at']);
    $compiled[$label] = ['sql' => $builder->toSql(), 'bindings' => $builder->getBindings()];
}
echo json_encode($compiled, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
