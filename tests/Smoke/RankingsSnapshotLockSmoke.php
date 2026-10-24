<?php
/**
 * Production CommunityController with a deterministic simulation of InnoDB
 * REPEATABLE READ snapshot vs a CURRENT SELECT FOR UPDATE on plugin_states.
 * This does NOT run PHP PDO, MySQL, HTTP or actual concurrent transactions.
 */
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class SnapshotRankDB {
    public bool $snapshotEnabled = true;
    public bool $liveEnabled = true;
    public bool $disableDuringRead = false;
    public bool $pendingDisable = false;
    public bool $transactionOpen = false;
    public bool $pluginLock = false;
    public bool $lockedWhenRankQueried = false;
    public bool $guarded = false;
    public int $transactions = 0;
    public int $locks = 0;
    public int $rankReads = 0;
    public int $snapshotReads = 0;
    public int $currentReads = 0;
    public function table(string $table): SnapshotRankQuery {return new SnapshotRankQuery($this,$table);}
    public function query(): SnapshotRankQuery {return new SnapshotRankQuery($this,'ranked');}
    public function transaction(callable $body):mixed {
        if ($this->transactionOpen) throw new \RuntimeException('Unexpected nested controller transaction');
        $this->transactions++;
        $this->transactionOpen=true;
        try {return $body();}
        finally {
            $this->transactionOpen=false;
            $this->pluginLock=false;
            if ($this->pendingDisable) {$this->liveEnabled=false;$this->pendingDisable=false;}
        }
    }
}
final class SnapshotRankQuery {
    private bool $locked = false;
    public function __construct(private SnapshotRankDB $db,private string $table) {}
    public function where(string $column,mixed $value):self {
        if ($this->table==='plugin_states' && ($column!=='key'||$value!=='rankings'))
            throw new \RuntimeException('Wrong plugin key');
        return $this;
    }
    public function whereIn(string $column,array $values):self{return $this;}
    public function whereColumn(string $a,string $b):self{return $this;}
    public function join(string $table,string $a,string $op,string $b):self{return $this;}
    public function selectRaw(string $sql):self{return $this;}
    public function select(string ...$names):self{return $this;}
    public function selectSub(mixed $q,string $alias):self{return $this;}
    public function fromSub(mixed $q,string $alias):self{return $this;}
    public function orderBy(string $column):self{return $this;}
    public function orderByDesc(string $column):self{return $this;}
    public function limit(int $limit):self{return $this;}
    public function lockForUpdate():self {
        if ($this->table!=='plugin_states' || !$this->db->transactionOpen)
            throw new \RuntimeException('Plugin lock MUST be inside the ranking transaction');
        $this->locked = $this->db->pluginLock = true;
        $this->db->locks++;
        return $this;
    }
    public function value(string $field):mixed {
        if($this->table!=='plugin_states'||$field!=='enabled')throw new \RuntimeException('Unexpected value lookup');
        if ($this->locked) {$this->db->currentReads++;return $this->db->liveEnabled;}
        $this->db->snapshotReads++;
        return $this->db->snapshotEnabled;
    }
    public function whereExists(callable $callback):self {
        $q = new SnapshotRankSubquery;
        $callback($q);
        if ($q->from!=='plugin_states as live_rankings'||($q->wheres['live_rankings.key']??null)!=='rankings'
            ||($q->wheres['live_rankings.enabled']??null)!==true) throw new \RuntimeException('Rankings query lost SQL gate');
        $this->db->guarded=true;
        return $this;
    }
    public function get():Collection {
        if ($this->table!=='ranked')throw new \RuntimeException('Unexpected list query '.$this->table);
        ++$this->db->rankReads;
        $this->db->lockedWhenRankQueried = $this->db->pluginLock && $this->db->transactionOpen;
        if ($this->db->disableDuringRead) {
            // Another administrator attempts UPDATE plugin_states now. An
            // InnoDB row lock makes that UPDATE wait until response completed.
            if ($this->db->pluginLock) $this->db->pendingDisable=true;
            else $this->db->liveEnabled=false;
        }
        return $this->db->guarded && !$this->db->snapshotEnabled ? collect() : collect([
            (object) ['id'=>7,'name'=>'Sample','posts_count'=>2,'reactions_count'=>1,'score'=>14],
        ]);
    }
}
final class SnapshotRankSubquery {
    public string $from=''; public array $wheres=[];
    public function selectRaw(string $v):self{return $this;}
    public function from(string $v):self{$this->from=$v;return $this;}
    public function where(string $key,mixed $value):self{$this->wheres[$key]=$value;return $this;}
}
$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]):never {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key):string{return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    (new \ReflectionClass(\Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor());
$db=new SnapshotRankDB;DB::swap($db);$controller=new CommunityController;$checks=0;
function rankAssert(bool $passed,string $detail):void {
    global $checks,$db;
    if (!$passed) {
        fwrite(STDERR,"RED-before: $detail (tx={$db->transactions}, locks={$db->locks}, snapshot={$db->snapshotReads}, current={$db->currentReads}, live=".($db->liveEnabled?'ON':'OFF').")\n");
        exit(1);
    }
    $checks++;
}
$cases = [
    // An already committed disable must be detected through a CURRENT read,
    // even if the surrounding session took its REPEATABLE READ snapshot earlier.
    ['disable committed after snapshot',true,false,false,404,0],
    ['disabled before request',false,false,false,404,0],
    ['concurrent disable queued at score query',true,true,true,200,1],
    ['enabled ranking unchanged',true,true,false,200,1],
];
foreach($cases as [$title,$snapshot,$live,$attemptDisable,$expectedStatus,$expectedReads]) {
    $db->snapshotEnabled=$snapshot;$db->liveEnabled=$live;$db->disableDuringRead=$attemptDisable;
    $db->pendingDisable=$db->pluginLock=$db->transactionOpen=$db->lockedWhenRankQueried=$db->guarded=false;
    $db->transactions=$db->locks=$db->rankReads=$db->snapshotReads=$db->currentReads=0;
    try{$response=$controller->rankings(Request::create('/api/rankings','GET'));
        $status=$response->getStatusCode();$body=$response->getData(true);}
    catch(HttpExceptionInterface $e){$status=$e->getStatusCode();$body=[];}
    rankAssert($status===$expectedStatus,"$title expected HTTP $expectedStatus, got $status");
    rankAssert($db->transactions===1&&$db->locks===1&&$db->currentReads===1,
        "$title must use a CURRENT plugin row lock in exactly one controller transaction");
    rankAssert($db->rankReads===$expectedReads,"$title unexpected score read count");
    if ($expectedStatus===200) {
        rankAssert($db->guarded&&$db->lockedWhenRankQueried, "$title score query not protected by plugin lock + SQL guard");
        rankAssert(($body['data'][0]['score']??null)===14, "$title valid scores changed");
    }
    if ($attemptDisable) {
        rankAssert(!$db->liveEnabled&&$db->snapshotEnabled,
            "$title external plugin update should commit only after the controller transaction");
    }
}
echo "PASS: $checks real controller checks of simulated RR snapshot/row lock (NOT PDO/HTTP/concurrency)\n";
}
