<?php
/** Production CommunityController::rankings with deterministic fake queries: NOT HTTP/PDO. */
namespace App\Services {
    class ForumService {
        public static function visibleCategoryIds(?object $viewer): array {return [3];}
        public static function publicUser(object $u): array {return ['id'=>(int)$u->id,'name'=>$u->name];}
    }
}
namespace {
require dirname(__DIR__, 2).'/vendor/autoload.php';
use App\Http\Controllers\CommunityController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
final class RankingFakeDB {
    public int $pluginReads=0;public int $rankReads=0;
    public bool $enabled=true;public bool $disableOnRankRead=false;public bool $disableOnSerialize=false;
    public bool $guard=false;
    public bool $locked=false;public bool $pendingDisable=false;public int $transactions=0;
    public function transaction(callable $fn):mixed {
        $this->transactions++;
        try{return $fn();}
        finally {$this->locked=false;if($this->pendingDisable){$this->enabled=false;$this->pendingDisable=false;}}
    }
    public function table(string $table): RankingFakeQuery{return new RankingFakeQuery($this,$table);}
    public function query(): RankingFakeQuery{return new RankingFakeQuery($this,'ranked');}
}
final class RankingFakeQuery {
    public function __construct(private RankingFakeDB $db,private string $table) {}
    public function where(string $column,mixed $arg,mixed $extra=null):self{return $this;}
    public function whereIn(string $column,array $values):self{return $this;}
    public function whereColumn(string $a,string $b):self{return $this;}
    public function join(string $table,string $a,string $operator,string $b):self{return $this;}
    public function selectRaw(string $expr):self{return $this;}
    public function select(string ...$columns):self{return $this;}
    public function selectSub(mixed $query,string $alias):self{return $this;}
    public function fromSub(mixed $query,string $alias):self{return $this;}
    public function orderByDesc(string $column):self{return $this;}
    public function orderBy(string $column):self{return $this;}
    public function limit(int $limit):self{return $this;}
    public function lockForUpdate():self {
        if($this->table!=='plugin_states')throw new \RuntimeException('Unexpected lock');
        $this->db->locked=true;return $this;
    }
    public function value(string $field):bool {
        if($this->table!=='plugin_states'||$field!=='enabled')throw new \RuntimeException('Unexpected plugin value');
        $this->db->pluginReads++;return $this->db->enabled;
    }
    public function whereExists(callable $cb): self {
        $q=new RankingFakeSubQuery;$cb($q);
        if($q->from!=='plugin_states as live_rankings'||($q->where['live_rankings.key']??null)!=='rankings'||($q->where['live_rankings.enabled']??null)!==true)
            throw new \RuntimeException('Malformed plugin predicate');
        $this->db->guard=true;return $this;
    }
    public function get():Collection {
        if($this->table!=='ranked')throw new \RuntimeException('Unexpected get '.$this->table);
        $this->db->rankReads++;
        if($this->db->disableOnRankRead){
            if($this->db->locked)$this->db->pendingDisable=true;
            else $this->db->enabled=false;
        }
        if($this->db->guard&&!$this->db->enabled)return collect();
        return collect([(object)['id'=>5,'name'=>'Sample','posts_count'=>2,'reactions_count'=>1,'score'=>14]]);
    }
}
final class RankingFakeSubQuery {
    public string $from=''; public array $where=[];
    public function selectRaw(string $expr):self {return $this;}
    public function from(string $table):self {$this->from=$table;return $this;}
    public function where(string $column,mixed $value):self {$this->where[$column]=$value;return $this;}
}
$app=new class extends Container {public function abort(int $code,string $message='',array $headers=[]):never {throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,$message,null,$headers);}};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key):string{return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,(new \ReflectionClass(\Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
$db=new RankingFakeDB;DB::swap($db);$controller=new CommunityController;$checks=0;
foreach ([
 ['already disabled',false,false,404,0],
 ['disable waits while scores load',true,true,200,1],
 ['enabled ranking',true,false,200,1]
] as [$label,$initial,$disableOnRead,$expected,$reads]) {
    $db->enabled=$initial;$db->disableOnRankRead=$disableOnRead;$db->guard=$db->locked=$db->pendingDisable=false;$db->transactions=0;$db->pluginReads=$db->rankReads=0;
    try {$result=$controller->rankings(Request::create('/api/rankings','GET'));$status=$result->getStatusCode();$body=$result->getData(true);}
    catch(HttpExceptionInterface $ex){$status=$ex->getStatusCode();$body=[];}
    if($status!==$expected) {fwrite(STDERR,"RED: $label expected HTTP $expected, got $status (rankReads={$db->rankReads}, pluginReads={$db->pluginReads})\n");exit(1);}
    $checks++;
    if($db->rankReads!==$reads)throw new \RuntimeException('Unexpected query execution');$checks++;
    if($initial&&!$db->guard)throw new \RuntimeException('Rankings SELECT missing live plugin WHERE EXISTS');$checks++;
    if($status===200){if(count($body['data']??[])!==1||($body['data'][0]['score']??null)!==14)throw new \RuntimeException('Enabled scores changed');$checks++;}
}
echo "PASS: $checks real rankings controller fake DB revocation checks (not PDO/HTTP)\n";
}
