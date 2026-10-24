<?php
/** Real Laravel grammar compiles the production read-receipt mutation. No PDO. */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PrivateMessageReadReceiptQuery;
use Illuminate\Support\Facades\DB;

$out=[];
foreach (['sqlite','mysql','mariadb'] as $driver) {
    DB::setDefaultConnection($driver);
    $grammar=DB::connection()->getQueryGrammar();
    foreach ([1,2,3] as $viewer) {
        $q=PrivateMessageReadReceiptQuery::eligible($viewer,100);
        $values=['read_at'=>'2026-10-24 06:40:00','updated_at'=>'2026-10-24 06:40:00'];
        $out[$driver][(string)$viewer]=[
            'sql'=>$grammar->compileUpdate($q,$values),
            'bindings'=>$grammar->prepareBindingsForUpdate($q->getRawBindings(),$values),
        ];
    }
}
echo json_encode($out,JSON_THROW_ON_ERROR);
