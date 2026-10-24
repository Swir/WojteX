<?php
/** Compile the production profile UPDATE for three Laravel grammars without PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\ProfileUpdateQuery;
use Illuminate\Support\Facades\DB;

$values = [
    'with_pref' => ['name'=>'Renamed','bio'=>'Updated biography','allow_private_messages'=>false,'updated_at'=>'2026-10-23 19:00:00'],
    'without_pref' => ['name'=>'Renamed','bio'=>'Updated biography','updated_at'=>'2026-10-23 19:00:00'],
];
$out = [];
foreach (['sqlite','mysql','mariadb'] as $driver) {
    DB::setDefaultConnection($driver);
    $connection=DB::connection();
    $grammar=$connection->getQueryGrammar();
    foreach ($values as $mode=>$update) {
        $query=ProfileUpdateQuery::eligible(7);
        $out[$driver][$mode] = [
            'sql'=>$grammar->compileUpdate($query, $update),
            'bindings'=>$grammar->prepareBindingsForUpdate($query->getRawBindings(), $update),
        ];
    }
}
echo json_encode($out, JSON_THROW_ON_ERROR);
