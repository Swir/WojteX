<?php
/** Calls the actual report controller against a deterministic fake DB.
 * Fake DB models ban/delete/hide AFTER preflight; NOT Laravel HTTP, PDO or true concurrency.
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

final class PMReportRaceRequest extends Request {
    public function validate(array $rules, ...$params): array {return ['reason'=>'spam','details'=>'Evidence'];}
}
final class PMReportRaceDB {
    public bool $actorExists=true,$banned=false,$hidden=false,$present=false,$messageExists=true;
    public ?string $flip=null;
    public int $writes=0,$guardCalls=0;
    public function table(string $table): PMReportRaceQuery {return new PMReportRaceQuery($this,$table);}
}
final class PMReportRaceQuery extends \Illuminate\Database\Query\Builder {
    public array $filters=[],$nulls=[],$cols=[];
    public bool $guardActor=false;
    public string $selection='';
    public function __construct(private PMReportRaceDB $db, public string $table) {}
    public function where($column,$operator=null,$value=null,$boolean='and'): self {
        $this->filters[$column]=$value??$operator;return $this;
    }
    public function whereNull($column,$boolean='and',$not=false): self {$this->nulls[]=$column;return $this;}
    public function from($table,$as=null): self {$this->from=$table;return $this;}
    public function selectRaw($sql,array $params=[]): self {$this->selection=$sql;return $this;}
    public function whereColumn($left,$operator=null,$right=null,$boolean='and'): self {
        $this->cols[]=$left.($right===null?'=':$operator).($right??$operator);return $this;
    }
    public function whereExists($callback,$boolean='and',$not=false): self {
        $q=new self($this->db,'subquery');$callback($q);
        $this->guardActor=$q->from==='users as live_reporter'
            && $q->selection==='1'
            && $q->cols===['live_reporter.id=private_messages.recipient_id']
            && ($q->filters['live_reporter.is_banned']??null)===false;
        if (!$this->guardActor) throw new RuntimeException('invalid reporter SQL guard');
        ++$this->db->guardCalls;
        return $this;
    }
    public function first($columns=['*']): ?object {
        if ($this->table!=='private_messages') throw new RuntimeException('unexpected first');
        if (!$this->db->messageExists) return null;
        $row=(object)['recipient_id'=>7,'recipient_hidden_at'=>$this->db->hidden?'hidden':null];
        if ($this->db->flip==='ban') $this->db->banned=true;
        if ($this->db->flip==='delete') $this->db->actorExists=false;
        if ($this->db->flip==='hide') $this->db->hidden=true;
        return $row;
    }
    public function exists(): bool {
        if ($this->table==='users') return $this->db->actorExists && !$this->db->banned
            && ($this->filters['id']??null)===7 && ($this->filters['is_banned']??null)===false;
        if ($this->table==='private_message_reports') return $this->db->present
            && ($this->filters['reporter_id']??null)===7 && ($this->filters['message_id']??null)===100;
        if ($this->table==='private_messages') return $this->db->messageExists
            && !$this->db->hidden && ($this->filters['id']??null)===100
            && ($this->filters['recipient_id']??null)===7
            && in_array('recipient_hidden_at',$this->nulls,true)
            && (!$this->guardActor || ($this->db->actorExists && !$this->db->banned));
        throw new RuntimeException('unexpected exists '.$this->table);
    }
    public function insertOrIgnoreUsing(array $columns,mixed $candidate): int {
        if ($this->table!=='private_message_reports' || !$candidate instanceof self
            || $candidate->table!=='private_messages'
            || !str_contains($candidate->selection, 'reporter_id')
            || ($candidate->filters['id']??null)!==100
            || ($candidate->filters['recipient_id']??null)!==7
            || !in_array('recipient_hidden_at',$candidate->nulls,true)
            || count($columns)!==9) throw new RuntimeException('unexpected PM-report insert');
        // If the real SQL lacks a LIVE reporter guard, this simulator deliberately
        // permits an INSERT after ban: reproduces the bug in the old service.
        if (!$this->db->messageExists || $this->db->hidden || $this->db->present) return 0;
        if ($candidate->guardActor && (!$this->db->actorExists || $this->db->banned)) return 0;
        $this->db->present=true;++$this->db->writes;return 1;
    }
}

$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]): never {throw new HttpException($code,$message,null,$headers);}
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
$db=new PMReportRaceDB();DB::swap($db);
$controller=new PrivateMessageController();$checks=0;
$cases=[
    ['ban',false,true,403,0,'ban after request preflight'],
    ['delete',false,true,403,0,'reporter deleted after preflight'],
    ['hide',false,true,404,0,'PM hidden after request preflight'],
    [null,false,true,200,1,'active visible recipient reports'],
    [null,true,true,200,0,'active repeated report idempotent'],
    ['ban',true,true,403,0,'banned repeated report is not success'],
    ['hide',true,true,404,0,'hidden repeated report is not success'],
    [null,false,false,404,0,'nonexistent PM does not report'],
];
foreach($cases as [$flip,$present,$messageExists,$expected,$writes,$label]){
    $db->actorExists=true;$db->banned=false;$db->hidden=false;$db->present=$present;
    $db->messageExists=$messageExists;$db->flip=$flip;$db->writes=0;$db->guardCalls=0;
    $req=PMReportRaceRequest::create('/api/messages/100/report','POST');
    $req->setUserResolver(static fn()=>(object)['id'=>7,'is_banned'=>false,'role'=>'member']);
    try {$status=$controller->report($req,100)->getStatusCode();}
    catch (HttpExceptionInterface $e) {$status=$e->getStatusCode();}
    if($status!==$expected || $db->writes!==$writes) {
        fwrite(STDERR,"RED: $label expected {$expected} and {$writes} writes, got {$status} and {$db->writes} writes; guardCalls={$db->guardCalls}\n");exit(1);
    }
    $checks+=2;
    if($messageExists && $db->guardCalls<1){
        fwrite(STDERR,"FAIL: no live reporter guard in report INSERT for $label\n");exit(1);
    }
    if($messageExists) ++$checks;
}
echo "PASS: $checks real-controller PM-report race assertions via fake DB (NOT PDO/HTTP)\n";
