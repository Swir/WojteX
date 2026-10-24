<?php
/**
 * Calls the real PrivateMessageController with a deterministic fake DB. It
 * simulates a ban/account deletion AFTER session validation but BEFORE write.
 * Not a Laravel HTTP, PDO or concurrent-transaction test.
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

final class PMBlockRequest extends Request {
    public function validate(array $rules, ...$params): array { return ['user_id'=>11]; }
}
final class PMBlockDB {
    public bool $liveActor=true;
    public bool $liveBanned=false;
    public bool $liveOther=true;
    public bool $present=false;
    public ?string $flip=null;
    public int $writes=0;
    public int $guarded=0;
    public function table(string $name): PMBlockQuery {return new PMBlockQuery($this,$name);}
}
final class PMBlockQuery {
    public array $filters=[];
    public array $columns=[];
    public array $joins=[];
    public string $from='';
    public ?string $projection=null;
    public array $bindings=[];
    public bool $protectedDelete=false;
    public function __construct(private PMBlockDB $db, public string $table) {}
    public function where(mixed $column,mixed $operator=null,mixed $value=null): self {
        if (is_array($column)) $this->filters += $column;
        else $this->filters[$column]=$value??$operator;
        return $this;
    }
    public function whereColumn(string $left,string $operator,string|null $right=null): self {
        $this->columns[]=$left.($right===null?'=':$operator).($right??$operator); return $this;
    }
    public function crossJoin(string $table): self {$this->joins[]=$table;return $this;}
    public function from(string $table): self {$this->from=$table;return $this;}
    public function selectRaw(string $sql,array $bindings=[]): self {$this->projection=$sql;$this->bindings=$bindings;return $this;}
    public function whereExists(Closure $callback): self {
        $inner=new self($this->db,'subquery');$callback($inner);
        $this->protectedDelete=$inner->from==='users as actor'
            && ($inner->filters['actor.is_banned']??null)===false
            && ($inner->columns===['actor.id=private_message_blocks.blocker_id']);
        return $this;
    }
    private function candidateGuard(): bool {
        return $this->table==='users as actor'
            && $this->joins===['users as other']
            && ($this->filters['actor.id']??null)===7
            && ($this->filters['actor.is_banned']??null)===false
            && ($this->filters['other.id']??null)===11
            && $this->columns===['actor.id!=other.id'];
    }
    public function exists(): bool {
        if ($this->table==='users' && ($this->filters['id']??null)===11) {
            $result=$this->db->liveOther;
            if ($this->db->flip==='ban') $this->db->liveBanned=true;
            if ($this->db->flip==='actor-delete') $this->db->liveActor=false;
            if ($this->db->flip==='target-delete') $this->db->liveOther=false;
            return $result;
        }
        if ($this->candidateGuard()) return $this->db->liveActor && !$this->db->liveBanned && $this->db->liveOther;
        if ($this->table==='users as actor') return $this->db->liveActor && !$this->db->liveBanned
            && ($this->filters['actor.id']??null)===7 && ($this->filters['actor.is_banned']??null)===false;
        throw new RuntimeException('unexpected exists '.$this->table);
    }
    public function insertOrIgnore(array $data): int {
        if ($this->table!=='private_message_blocks') throw new RuntimeException('unexpected table');
        if ($this->db->present) return 0;
        ++$this->db->writes;$this->db->present=true;return 1;
    }
    public function insertOrIgnoreUsing(array $columns,mixed $candidate): int {
        if ($this->table!=='private_message_blocks' || !$candidate instanceof self
            || !$candidate->candidateGuard()
            || $columns!==['blocker_id','blocked_id','created_at','updated_at']
            || !str_contains($candidate->projection??'','actor.id AS blocker_id')
            || !str_contains($candidate->projection??'','other.id AS blocked_id')
            || count($candidate->bindings)!==2) {
            throw new RuntimeException('block INSERT has no live DB guard');
        }
        ++$this->db->guarded;
        if (!$this->db->liveActor || $this->db->liveBanned || !$this->db->liveOther || $this->db->present) return 0;
        ++$this->db->writes;$this->db->present=true;return 1;
    }
    public function delete(): int {
        if ($this->table!=='private_message_blocks') throw new RuntimeException('unexpected DELETE');
        if ($this->protectedDelete) ++$this->db->guarded;
        if (!$this->db->present) return 0;
        if ($this->protectedDelete && (!$this->db->liveActor || $this->db->liveBanned)) return 0;
        ++$this->db->writes;$this->db->present=false;return 1;
    }
}

$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]): never {throw new HttpException($code,$message,null,$headers);}
};
Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string{return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json($data=[],$status=200,array $headers=[],$options=0): JsonResponse {
            return new JsonResponse($data,$status,$headers,$options);
        }
    });
$db=new PMBlockDB();DB::swap($db);
$controller=new PrivateMessageController();$checks=0;
$cases=[
    ['block',false,'ban',403,false,0,'ban after block preflight'],
    ['block',false,'actor-delete',403,false,0,'actor deleted after preflight'],
    ['block',false,'target-delete',422,false,0,'target deleted after preflight'],
    ['block',false,null,200,true,1,'live actor creates block'],
    ['block',true,null,200,true,0,'idempotent existing block'],
    ['unblock',true,'pre-banned',403,true,0,'banned actor cannot remove block'],
    ['unblock',true,'pre-deleted',403,true,0,'deleted actor cannot remove block'],
    ['unblock',true,null,200,false,1,'live actor removes block'],
    ['unblock',false,null,200,false,0,'idempotent missing block'],
];
foreach($cases as [$method,$present,$flip,$expectedStatus,$expectedPresent,$expectedWrites,$label]){
    $db->liveActor=true;$db->liveOther=true;$db->liveBanned=false;$db->present=$present;
    $db->flip=$flip;$db->writes=0;$db->guarded=0;
    if ($flip==='pre-banned') $db->liveBanned=true;
    if ($flip==='pre-deleted') $db->liveActor=false;
    $request=PMBlockRequest::create('/api/messages/blocks','POST');
    $request->setUserResolver(static fn()=>(object)['id'=>7,'is_banned'=>false,'role'=>'member']);
    try {
        $response=$method==='block'?$controller->blockContact($request):$controller->unblockContact($request,11);
        $actual=$response->getStatusCode();
    } catch(HttpExceptionInterface $exception) {$actual=$exception->getStatusCode();}
    if($actual!==$expectedStatus||$db->present!==$expectedPresent||$db->writes!==$expectedWrites){
        fwrite(STDERR,"RED: $label expected {$expectedStatus} present=".json_encode($expectedPresent)
            ." writes={$expectedWrites}; actual={$actual} present=".json_encode($db->present)
            ." writes={$db->writes} guards={$db->guarded}\n");exit(1);
    }
    $checks+=3;
    // The mutation must have passed through a guard in the same SQL statement.
    if($db->guarded!==1){fwrite(STDERR,"FAIL: $label missing live write guard\n");exit(1);}
    ++$checks;
}
echo "PASS: $checks real-controller PM block/unblock checks with fake DB (NOT PDO/HTTP)\n";
