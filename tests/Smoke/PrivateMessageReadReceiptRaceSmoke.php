<?php
/** Actual controller, mutable fake DB. No PHP PDO, HTTP, MySQL or proven physical concurrency. */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\PrivateMessageController;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PMReadReceiptDB {
    public bool $active=true, $banned=false, $messageExists=true, $hidden=false, $read=false;
    public int $writes=0, $liveGuards=0, $viewer=2;
    public ?string $flip=null;
    public function table(string $table): PMReadReceiptQuery { return new PMReadReceiptQuery($this, $table); }
}
final class PMReadReceiptQuery extends \Illuminate\Database\Query\Builder {
    public array $filters=[], $nulls=[], $notNulls=[];
    public bool $liveGuard=false;
    public function __construct(private PMReadReceiptDB $db, public string $table) {}
    public function where($column,$operator=null,$value=null,$boolean='and'):self {
        $this->filters[$column]=$value??$operator; return $this;
    }
    public function whereNull($column,$boolean='and',$not=false):self { $this->nulls[]=$column; return $this; }
    public function whereNotNull($column,$boolean='and'):self { $this->notNulls[]=$column; return $this; }
    public function selectRaw($expression,array $bindings=[]):self { $this->columns=[$expression];return $this; }
    public function from($table,$as=null):self { $this->from=$table;return $this; }
    public function whereColumn($first,$operator=null,$second=null,$boolean='and'):self {
        $right=$second??$operator;
        $this->filters['column:'.$first]=$right;
        return $this;
    }
    public function whereExists($callback,$boolean='and',$not=false):self {
        $q=new self($this->db,'subquery');$callback($q);
        $this->liveGuard=$q->from==='users as live_reader'
          && ($q->columns[0]??null)==='1'
          && ($q->filters['live_reader.is_banned']??null)===false
          && ($q->filters['column:live_reader.id']??null)==='private_messages.recipient_id';
        if (!$this->liveGuard) throw new RuntimeException('missing or invalid correlated active-recipient guard');
        ++$this->db->liveGuards; return $this;
    }
    public function first($columns=['*']):?object {
        if ($this->table!=='private_messages') throw new RuntimeException('bad preflight table');
        if (!$this->db->messageExists) return null;
        $message=(object)['id'=>100,'sender_id'=>1,'recipient_id'=>2,
            'sender_hidden_at'=>null,'recipient_hidden_at'=>$this->db->hidden?'old':null,
            'read_at'=>$this->db->read?'old':null];
        if ($this->db->flip==='ban') $this->db->banned=true;
        elseif ($this->db->flip==='delete') $this->db->active=false;
        elseif ($this->db->flip==='hide') $this->db->hidden=true;
        elseif ($this->db->flip==='read') $this->db->read=true;
        return $message;
    }
    public function exists():bool {
        if ($this->table==='users')
            return $this->db->active && !$this->db->banned
                && ($this->filters['id']??null)===$this->db->viewer
                && ($this->filters['is_banned']??null)===false;
        if ($this->table==='private_messages')
            return $this->db->messageExists && $this->db->read && !$this->db->hidden
                && ($this->filters['id']??null)===100 && ($this->filters['recipient_id']??null)===2
                && in_array('recipient_hidden_at',$this->nulls,true)
                && in_array('read_at',$this->notNulls,true);
        throw new RuntimeException('unexpected exists table');
    }
    public function update(array $values):int {
        if ($this->table!=='private_messages' || !isset($values['read_at'],$values['updated_at'])
            || ($this->filters['id']??null)!==100 || ($this->filters['recipient_id']??null)!==$this->db->viewer
            || !in_array('recipient_hidden_at',$this->nulls,true)
            || !in_array('read_at',$this->nulls,true)) throw new RuntimeException('unsafe/unscoped read receipt UPDATE');
        if (!$this->db->messageExists || $this->db->viewer!==2 || $this->db->hidden || $this->db->read
            || ($this->liveGuard && (!$this->db->active || $this->db->banned))) return 0;
        $this->db->read=true; ++$this->db->writes; return 1;
    }
}
$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]):never {
        throw new HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key):string {return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,new class extends \Illuminate\Routing\ResponseFactory {
    public function __construct() {}
    public function json($data=[],$status=200,array $headers=[],$options=0):JsonResponse {
        return new JsonResponse($data,$status,$headers,$options);
    }
});
$db=new PMReadReceiptDB();DB::swap($db);$controller=new PrivateMessageController();$checks=0;
$cases=[
    [2,'ban',true,false,false,403,0,'ban between preflight and UPDATE'],
    [2,null,true,false,false,200,1,'recipient marks unread PM'],
    [2,null,true,false,true,200,0,'idempotent already-read PM'],
    [2,'delete',true,false,false,403,0,'deletion between preflight and UPDATE'],
    [2,'hide',true,false,false,404,0,'hide between preflight and UPDATE'],
    [2,'read',true,false,false,200,0,'another read between preflight and UPDATE'],
    [1,null,true,false,false,404,0,'sender cannot mark recipient copy'],
    [3,null,true,false,false,404,0,'stranger cannot mark recipient copy'],
    [2,null,true,true,false,404,0,'hidden recipient copy'],
    [2,null,false,false,false,404,0,'unknown message'],
];
foreach($cases as [$viewer,$flip,$exists,$hidden,$read,$expected,$writes,$label]) {
    $db->viewer=$viewer;$db->flip=$flip;$db->messageExists=$exists;$db->hidden=$hidden;
    $db->read=$read;$db->active=true;$db->banned=false;$db->writes=0;$db->liveGuards=0;
    $request=Request::create('/api/messages/100/read','PATCH');
    $request->setUserResolver(static fn()=>(object)['id'=>$viewer,'is_banned'=>false,'role'=>'member']);
    try {$status=$controller->markRead($request,100)->getStatusCode();}
    catch(HttpExceptionInterface $exception) {$status=$exception->getStatusCode();}
    if($status!==$expected || $db->writes!==$writes) {
        fwrite(STDERR,"RED: $label expected status=$expected writes=$writes; got status=$status writes={$db->writes} guards={$db->liveGuards}\n");exit(1);
    }
    $checks+=2;
    if ($viewer===2 && $exists && !$hidden) {
        if ($db->liveGuards!==1) {fwrite(STDERR,"RED: missing live-actor authorization in SQL for $label\n");exit(1);}
        ++$checks;
    }
}
echo "PASS: $checks actual-controller read-receipt checks on fake DB (NOT PHP PDO/HTTP)\n";
