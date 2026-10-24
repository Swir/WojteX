<?php
/**
 * Execute the actual CommunityController::shouts action with a deterministic
 * query-counting database seam. No PDO/HTTP: confirms bounded per-poll reads
 * and unchanged response shapes/order for a 30/100-message window.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\CommunityController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;

// Only ASCII fixture names are used when mbstring is absent in the host.
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null): string {return substr($value, $start, $length ?? strlen($value));} }
if (!function_exists('mb_strtoupper')) { function mb_strtoupper(string $value): string {return strtoupper($value);} }

final class ShoutBatchRequest extends Request
{
    public function validate(array $rules): array
    {
        return $this->has('after') ? ['after' => (int)$this->input('after')] : [];
    }
}
final class ShoutBatchDb
{
    public array $calls=[];
    public bool $enabled=true;
    public function __construct(public array $shouts, public array $users) {}
    public function table(string $name): ShoutBatchQuery {return new ShoutBatchQuery($this, $name);}
}
final class ShoutBatchSubquery
{
    public string $source = '';
    public array $conditions = [];
    public function selectRaw(string $expression): self { return $this; }
    public function from(string $source): self { $this->source=$source; return $this; }
    public function where(string $column, mixed $operator, mixed $value=null): self
    { $this->conditions[$column]=$value ?? $operator; return $this; }
}
final class ShoutBatchQuery
{
    private ?int $after=null;
    private ?int $limit=null;
    private bool $descending=false;
    private array $ids=[];
    private bool $livePluginGate=false;
    public function __construct(private ShoutBatchDb $db, private string $table) {}
    public function where(string $field, mixed $op, mixed $value=null): self
    {
        if ($this->table==='shouts' && $field==='id' && $op==='>') $this->after=(int)$value;
        return $this;
    }
    public function whereExists(callable $callback): self
    {
        $q=new ShoutBatchSubquery(); $callback($q);
        if ($q->source!=='plugin_states as live_shoutbox'
            || ($q->conditions['live_shoutbox.key'] ?? null)!=='shoutbox'
            || ($q->conditions['live_shoutbox.enabled'] ?? null)!==true)
            throw new RuntimeException('Missing live shoutbox read authorization');
        $this->livePluginGate=true;
        return $this;
    }
    public function whereIn(string $field, array $ids): self
    {
        if ($this->table!=='users' || $field!=='id') throw new RuntimeException('Unexpected whereIn');
        $this->ids=$ids;
        return $this;
    }
    public function orderBy(string $column): self {return $this;}
    public function orderByDesc(string $column): self {$this->descending=true;return $this;}
    public function limit(int $count): self {$this->limit=$count;return $this;}
    public function value(string $field): mixed
    {
        $this->db->calls[]='plugin_states.value';
        if ($this->table!=='plugin_states' || $field!=='enabled') throw new RuntimeException('Invalid plugin query');
        return $this->db->enabled;
    }
    public function find(int $id): ?object
    {
        $this->db->calls[]=$this->table.'.find';
        if ($this->table!=='users') throw new RuntimeException('Invalid find query');
        return $this->db->users[$id] ?? null;
    }
    public function get(array $columns=['*']): Collection
    {
        $this->db->calls[]=$this->table.'.get';
        if ($this->table==='users') {
            return collect($this->ids)->filter(fn($id)=>isset($this->db->users[(int)$id]))
                ->map(fn($id)=>$this->db->users[(int)$id])->values();
        }
        if ($this->table!=='shouts') throw new RuntimeException('Invalid collection query');
        if (!$this->livePluginGate) throw new RuntimeException('Read was not gated by current plugin state');
        $items=collect($this->db->shouts)->filter(fn($s)=>$this->after===null || $s->id>$this->after)
            ->sortBy('id', SORT_NUMERIC, $this->descending)->values();
        return $this->limit===null ? $items : $items->take($this->limit)->values();
    }
}
$app=new class extends Container {
    public function abort(int $code, string $message='', array $headers=[]): never
    {throw new HttpException($code, $message, null, $headers);}
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('translator', new class {
    public function get(string $key): string {return $key;}
});
$factory=(new ReflectionClass(\Illuminate\Routing\ResponseFactory::class))->newInstanceWithoutConstructor();
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, $factory);
$shouts=[]; $users=[];
for ($i=1;$i<=125;$i++) {
    $shouts[]=(object)['id'=>$i,'user_id'=>$i,'body'=>'Message '.$i,'created_at'=>'2026-10-23 12:00:00'];
    $users[$i]=(object)['id'=>$i,'name'=>'Author '.$i,'role'=>'member','color'=>'cyan','bio'=>'','created_at'=>'2026-01-01'];
}
$db=new ShoutBatchDb($shouts,$users); DB::swap($db);
$controller=new CommunityController();$checks=0;
foreach ([
    [null,30,96,125],
    [25,100,26,125],
    [124,1,125,125],
    [125,0,null,null],
] as [$after,$count,$first,$last]) {
    $db->calls=[];
    $request=ShoutBatchRequest::create('/api/shouts'.($after===null?'':'?after='.$after), 'GET');
    $response=$controller->shouts($request);
    $body=$response->getData(true);
    $rows=$body['data'];
    if (count($rows)!==$count) throw new RuntimeException('Wrong shout count: '.count($rows).' vs '.$count);
    $checks++;
    if ($count) {
        if ($rows[0]['id']!==$first || $rows[$count-1]['id']!==$last)
            throw new RuntimeException('Shout order changed');
        $checks++;
        foreach ($rows as $r) {
            if ($r['author']['id']!==$r['id'] || $r['author']['name']!=='Author '.$r['id'] || $r['body']!=='Message '.$r['id'])
                throw new RuntimeException('Author/message mismatch for '.$r['id']);
            $checks++;
        }
    }
    if (count(array_filter($db->calls,fn($call)=>str_starts_with($call,'users.')))!==($count>0?1:0))
        throw new RuntimeException('N+1 author DB access: '.json_encode(array_count_values($db->calls)));
    $checks++;
    if (count($db->calls)!==($count>0?4:3))
        throw new RuntimeException('Unbounded per-poll queries: '.json_encode(array_count_values($db->calls)));
    $checks++;
}
echo "PASS: {$checks} bounded author query/response checks, actual CommunityController with fake DB, no PDO/HTTP\n";
