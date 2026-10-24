<?php
/** Exercise real PrivateMessageController::hide with deterministic post-preflight changes.
 * Fake DB is NOT PDO, HTTP, or proof of concurrency on a real database.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\PrivateMessageController;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PMHideRaceDB {
    public bool $actorExists=true, $banned=false, $messageExists=true;
    public bool $senderHidden=false, $recipientHidden=false;
    public int $writes=0, $guardCalls=0, $flipViewer=1;
    public ?string $flip=null;
    public function table(string $table): PMHideRaceQuery {return new PMHideRaceQuery($this,$table);}
}
final class PMHideRaceQuery extends \Illuminate\Database\Query\Builder {
    public array $filters=[], $nulls=[], $columnGuards=[];
    public bool $liveGuard=false;
    public string $selection='';
    public function __construct(private PMHideRaceDB $db, public string $table) {}
    public function where($column,$operator=null,$value=null,$boolean='and'): self {
        $this->filters[$column]=$value??$operator; return $this;
    }
    public function whereNull($column,$boolean='and',$not=false): self {
        $this->nulls[]=$column;return $this;
    }
    public function selectRaw($expression,array $bindings=[]): self {$this->selection=$expression;return $this;}
    public function from($table,$as=null): self {$this->from=$table;return $this;}
    public function whereColumn($first,$operator=null,$second=null,$boolean='and'): self {
        $this->columnGuards[]=$first.($second===null?'=':$operator).($second??$operator);return $this;
    }
    public function whereExists($callback,$boolean='and',$not=false): self {
        $q=new self($this->db,'subquery');$callback($q);
        $this->liveGuard=$q->from==='users as live_hider' && $q->selection==='1'
            && ($q->filters['live_hider.is_banned']??null)===false
            && count($q->columnGuards)===1
            && in_array($q->columnGuards[0],[
                'live_hider.id=private_messages.sender_id',
                'live_hider.id=private_messages.recipient_id'],true);
        if (!$this->liveGuard) throw new RuntimeException('bad live-user predicate');
        ++$this->db->guardCalls;return $this;
    }
    public function first($columns=['*']): ?object {
        if ($this->table!=='private_messages') throw new RuntimeException('unexpected first query');
        if (!$this->db->messageExists) return null;
        $row=(object)['sender_id'=>1,'recipient_id'=>2];
        if ($this->db->flip==='ban') $this->db->banned=true;
        if ($this->db->flip==='delete') $this->db->actorExists=false;
        if ($this->db->flip==='hide') {
            $viewer=$this->db->flipViewer;
            if ($viewer===1) $this->db->senderHidden=true;
            else $this->db->recipientHidden=true;
        }
        return $row;
    }
    public function exists(): bool {
        if ($this->table!=='users') throw new RuntimeException('unexpected exists query');
        return $this->db->actorExists && !$this->db->banned
            && ($this->filters['id']??null)===$this->db->flipViewer
            && ($this->filters['is_banned']??null)===false;
    }
    public function update(array $values): int {
        if ($this->table!=='private_messages') throw new RuntimeException('unexpected update query');
        $viewer=$this->db->flipViewer;
        $sender=$viewer===1;
        $col=$sender?'sender_hidden_at':'recipient_hidden_at';
        $owner=$sender?'sender_id':'recipient_id';
        if (!isset($values[$col],$values['updated_at']) || ($this->filters[$owner]??null)!==$viewer
            || ($this->filters['id']??null)!==100 || !in_array($col,$this->nulls,true)) {
            throw new RuntimeException('unconstrained mailbox UPDATE');
        }
        $visible=$sender?!$this->db->senderHidden:!$this->db->recipientHidden;
        if (!$this->db->messageExists || !$visible || ($this->liveGuard && (!$this->db->actorExists || $this->db->banned))) return 0;
        if ($sender) $this->db->senderHidden=true; else $this->db->recipientHidden=true;
        ++$this->db->writes;
        return 1;
    }
}
$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]): never {
        throw new HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string{return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json($data=[],$status=200,array $headers=[],$options=0): JsonResponse {
            return new JsonResponse($data,$status,$headers,$options);
        }
    });
$db=new PMHideRaceDB();DB::swap($db);
$controller=new PrivateMessageController();$checks=0;
$cases=[
    [1,null,true,false,false,200,1,'sender hides own visible copy'],
    [2,null,true,false,false,200,1,'recipient hides own visible copy'],
    [1,'ban',true,false,false,403,0,'sender banned after preflight'],
    [2,'ban',true,false,false,403,0,'recipient banned after preflight'],
    [1,'delete',true,false,false,403,0,'sender deleted after preflight'],
    [2,'delete',true,false,false,403,0,'recipient deleted after preflight'],
    [1,'hide',true,false,false,404,0,'sender copy hidden after preflight'],
    [2,'hide',true,false,false,404,0,'recipient copy hidden after preflight'],
    [1,null,true,true,false,404,0,'sender repeated hide'],
    [2,null,true,false,true,404,0,'recipient repeated hide'],
    [3,null,true,false,false,404,0,'stranger cannot hide either copy'],
    [1,null,false,false,false,404,0,'missing message remains missing'],
];
foreach ($cases as [$viewer,$flip,$messageExists,$senderHidden,$recipientHidden,$expected,$writes,$label]) {
    $db->actorExists=true;$db->banned=false;$db->messageExists=$messageExists;
    $db->senderHidden=$senderHidden;$db->recipientHidden=$recipientHidden;
    $db->flip=$flip;$db->flipViewer=$viewer;$db->writes=0;$db->guardCalls=0;
    $req=Request::create('/api/messages/100','DELETE');
    $req->setUserResolver(static fn()=>(object)['id'=>$viewer,'is_banned'=>false,'role'=>'member']);
    try {$status=$controller->hide($req,100)->getStatusCode();}
    catch (HttpExceptionInterface $e) {$status=$e->getStatusCode();}
    if ($status!==$expected || $db->writes!==$writes) {
        fwrite(STDERR,"RED: $label expected $expected / $writes writes, got $status / {$db->writes}; guards={$db->guardCalls}\n");exit(1);
    }
    $checks+=2;
    if ($messageExists && $viewer!==3 && $db->guardCalls!==1) {
        fwrite(STDERR,"RED: $label no live-user SQL guard\n");exit(1);
    }
    if ($messageExists && $viewer!==3) ++$checks;
}
echo "PASS: $checks real-controller PM-hide fake-DB assertions (NOT PDO/HTTP)\n";
