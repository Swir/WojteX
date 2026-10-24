<?php
/** Real CommunityController::shouts with a deterministic fake DB. No PDO/HTTP/concurrency. */
require  dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\CommunityController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (!function_exists('mb_substr')) { function mb_substr(string $value,int $start,?int $len=null): string { return substr($value,$start,$len ?? strlen($value)); } }
if (!function_exists('mb_strtoupper')) { function mb_strtoupper(string $value): string {return strtoupper($value);} }
class LiveReadRequest extends Request {public function validate(array $rules): array {return $this->has('after') ? ['after'=>(int)$this->input('after')] : [];}}
class LiveReadDB {
    public int $pluginReads=0;
    public int $shoutReads=0;
    public bool $initialEnabled=true;
    public bool $currentEnabled=true;
    public bool $disableOnRead=false;
    public array $calls=[];
    public function table(string $name): LiveReadQuery {return new LiveReadQuery($this,$name);}
}
class LiveReadQuery {
    private bool $pluginGuard=false;
    private bool $descending=false;
    private ?int $after=null;
    private ?int $limit=null;
    private array $ids=[];
    public function __construct(private LiveReadDB $db,private string $source) {}
    public function where(string $key,mixed $cmp,mixed $value=null): self {
        if ($this->source==='shouts' && $key==='id' && $cmp==='>') $this->after=(int)$value;
        return $this;
    }
    public function whereExists(callable $callback): self {
        $child=new LiveReadSubquery;
        $callback($child);
        if ($child->source==='plugin_states as live_shoutbox' && ($child->wheres['live_shoutbox.key'] ?? null)==='shoutbox' && ($child->wheres['live_shoutbox.enabled'] ?? null)===true) $this->pluginGuard=true;
        else throw new RuntimeException('Shoutbox read guard malformed');
        return $this;
    }
    public function value(string $field): bool {
        if ($this->source!=='plugin_states'||$field!=='enabled') throw new RuntimeException('Unexpected plugin query');
        $this->db->pluginReads++;
        return $this->db->pluginReads===1?$this->db->initialEnabled:$this->db->currentEnabled;
    }
    public function whereIn(string $field,array $ids): self {if ($this->source!=='users') throw new RuntimeException('Unexpected whereIn');$this->ids=$ids;return $this;}
    public function orderBy(string $col):self{return $this;}
    public function orderByDesc(string $col):self{$this->descending=true;return $this;}
    public function limit(int $n):self{$this->limit=$n;return $this;}
    public function get(array $columns=['*']): Collection {
        $this->db->calls[]=$this->source.'.get';
        if ($this->source==='shouts') {
            $this->db->shoutReads++;
            if ($this->db->disableOnRead) $this->db->currentEnabled=false;
            if ($this->pluginGuard && !$this->db->currentEnabled) return collect();
            $rows=collect([(object)['id'=>1,'user_id'=>9,'body'=>'Old shout','created_at'=>'2026-10-24 00:00:00']]);
            if ($this->after!==null) $rows=$rows->filter(fn($v)=>$v->id>$this->after);
            return $rows->values();
        }
        if ($this->source==='users')return collect($this->ids)->map(fn($id)=>(object)['id'=>$id,'name'=>'Author','role'=>'member','color'=>'cyan','bio'=>'','created_at'=>'2026-01-01']);
        throw new RuntimeException('Unexpected get '.$this->source);
    }
}
class LiveReadSubquery {
    public string $source='';public array $wheres=[];
    public function selectRaw(string $sql):self{return $this;}
    public function from(string $name):self{$this->source=$name;return $this;}
    public function where(string $key,mixed $cmp,mixed $value=null):self{$this->wheres[$key]=$value??$cmp;return $this;}
}
$app=new class extends Container {public function abort(int $code,string $msg='',array $headers=[]):never {throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,$msg,null,$headers);}};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string{return $key;}});
$factory=(new ReflectionClass(\Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor();
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,$factory);
$db=new LiveReadDB;DB::swap($db);
$controller=new CommunityController();$checks=0;
$cases=[
    ['initial list turned off during query',false,true,true,false,404],
    ['incremental turned off during query',true,true,true,false,404],
    ['enabled initial list',false,true,false,true,200],
    ['enabled incremental list',true,true,false,true,200],
    ['already disabled',false,false,false,false,404],
];
foreach ($cases as [$name,$after,$initial,$turnOff,$current,$expected]){
    $db->initialEnabled=$initial;$db->currentEnabled=$current;$db->disableOnRead=$turnOff;
    $db->pluginReads=$db->shoutReads=0;$db->calls=[];
    $req=LiveReadRequest::create('/api/shouts'.($after?'?after=0':''),'GET');
    try{$result=$controller->shouts($req);$status=$result->getStatusCode();$body=$result->getData(true);}
    catch(HttpExceptionInterface $exception){$status=$exception->getStatusCode();$body=[];}
    if($status!==$expected) {fwrite(STDERR,"RED: $name expected HTTP $expected got $status; read={$db->shoutReads}, checks={$db->pluginReads}\n");exit(1);}
    $checks++;
    if ($status===200) {
        if(count($body['data']??[])!==1||($body['data'][0]['body']??null)!=='Old shout')throw new RuntimeException('Enabled data changed');
        $checks++;
    }
    if (!$initial&&$db->shoutReads!==0)throw new RuntimeException('Disabled initial check still queried');
    $checks++;
}
echo "PASS: $checks real controller fake DB revocation checks (not PDO/HTTP/concurrent)\n";
