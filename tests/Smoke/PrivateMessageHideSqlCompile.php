<?php
/** Compile real production conditional mailbox UPDATE through Laravel (without PHP PDO). */
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PrivateMessageHideQuery;
use Illuminate\Support\Facades\DB;

$out=[];
foreach (['sqlite','mysql','mariadb'] as $driver) {
    DB::setDefaultConnection($driver);
    $grammar=DB::connection()->getQueryGrammar();
    foreach (['sender'=>true,'recipient'=>false] as $label=>$isSender) {
        $values=[($isSender?'sender_hidden_at':'recipient_hidden_at')=>'2026-10-23 12:00:00',
            'updated_at'=>'2026-10-23 12:00:00'];
        foreach ([1,2,3] as $viewer) {
            $q=PrivateMessageHideQuery::eligible($viewer,100,$isSender);
            $out[$driver][$label][$viewer]=[
                'sql'=>$grammar->compileUpdate($q,$values),
                'bindings'=>$grammar->prepareBindingsForUpdate($q->getRawBindings(),$values),
            ];
        }
    }
}
echo json_encode($out,JSON_THROW_ON_ERROR);
