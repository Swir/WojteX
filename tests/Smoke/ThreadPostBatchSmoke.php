<?php
/**
 * Run the real ForumService post serialization against an instrumented in-memory
 * database seam. No PDO/HTTP: assert the 40-post page is bounded, and that the
 * optimized output matches the former per-post path byte-for-byte as PHP data.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\User;
use App\Services\ForumService;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

if (!function_exists('mb_substr')) {function mb_substr(string $v, int $s, ?int $l=null): string {return substr($v, $s, $l ?? strlen($v));}}
if (!function_exists('mb_strtoupper')) {function mb_strtoupper(string $v): string {return strtoupper($v);}}

final class PostBatchFakeDatabase
{
    public array $events=[];
    public function __construct(public array $users, public array $reactions, public array $attachments) {}
    public function table(string $table): PostBatchFakeQuery {return new PostBatchFakeQuery($this,$table);}
    public function record(string $operation): void {$this->events[]=$operation;}
}
final class PostBatchFakeQuery
{
    private array $predicates=[];
    private ?array $ids=null;
    private ?string $idField=null;
    private ?string $group=null;
    private ?string $order=null;
    public function __construct(private PostBatchFakeDatabase $db, private string $table) {}
    public function where(array|string $column, mixed $value=null): self
    {
        if (is_array($column)) {foreach ($column as $key=>$v) $this->predicates[$key]=$v;}
        else $this->predicates[$column]=$value;
        return $this;
    }
    public function whereIn(string $column, array $ids): self {$this->idField=$column;$this->ids=$ids;return $this;}
    public function select(string ...$cols): self {return $this;}
    public function selectRaw(string $sql): self {return $this;}
    public function groupBy(string $col): self {$this->group=$col;return $this;}
    public function orderBy(string $col): self {$this->order=$col;return $this;}
    private function rows(): array
    {
        $source=match ($this->table) {
            'users'=>$this->db->users,'reactions'=>$this->db->reactions,'attachments'=>$this->db->attachments,
            default=>throw new RuntimeException('Unexpected DB table '.$this->table)
        };
        $matched=array_values(array_filter($source, function($row): bool {
            if ($this->ids!==null && !in_array($row->{$this->idField},$this->ids,true)) return false;
            foreach($this->predicates as $key=>$value) if ($row->{$key} !== $value) return false;
            return true;
        }));
        if ($this->order!==null) usort($matched,fn($a,$b)=>$a->{$this->order}<=>$b->{$this->order});
        return $matched;
    }
    public function find(int $id): ?object
    {
        $this->db->record("{$this->table}.find");
        foreach ($this->rows() as $row) if ($row->id===$id) return $row;
        return null;
    }
    public function get(array $cols=['*']): Collection
    {
        $this->db->record("{$this->table}.get");
        return collect($this->rows());
    }
    public function count(): int {$this->db->record("{$this->table}.count");return count($this->rows());}
    public function exists(): bool {$this->db->record("{$this->table}.exists");return count($this->rows())>0;}
    public function pluck(string $value, ?string $key=null): Collection
    {
        $this->db->record("{$this->table}.pluck");
        $rows=$this->rows();
        if ($this->group==='post_id') {
            $counts=[];
            foreach ($rows as $row) $counts[$row->post_id]=($counts[$row->post_id]??0)+1;
            if ($value!=='total' || $key!=='post_id') throw new RuntimeException('Unexpected grouped pluck');
            return collect($counts);
        }
        $result=[];
        foreach ($rows as $row) {
            if ($key===null) $result[]=$row->{$value};
            else $result[$row->{$key}]=$row->{$value};
        }
        return collect($result);
    }
}

$app=new Container();Container::setInstance($app);Facade::setFacadeApplication($app);
$users=[];$posts=[];$reactions=[];$attachments=[];
for ($i=1;$i<=40;$i++) {
    $author=(($i-1)%20)+1;
    $users[]=(object)['id'=>$author,'name'=>'Author '.$author,'role'=>'member','color'=>'violet','bio'=>'','created_at'=>'2026-01-01'];
    $posts[]=(object)['id'=>$i,'thread_id'=>1,'user_id'=>$author,'body'=>'[b]Body '.$i.'[/b]','body_format'=>'bbcode','created_at'=>'2026-10-23','updated_at'=>'2026-10-23'];
    $reactions[]=(object)['id'=>$i*2,'post_id'=>$i,'user_id'=>100];
    if ($i%2===1) $reactions[]=(object)['id'=>$i*2+1,'post_id'=>$i,'user_id'=>999];
    if ($i%3===0) $attachments[]=(object)['id'=>$i+100,'post_id'=>$i,'name'=>'fixture-'.$i.'.txt','mime'=>'text/plain','size'=>10];
}
$users=array_values(array_unique($users,SORT_REGULAR));
$db=new PostBatchFakeDatabase($users,$reactions,$attachments);DB::swap($db);
$viewer=new User();$viewer->id=999;
$legacy=collect($posts)->map(fn($p)=>ForumService::postData($p,$viewer))->all();
$oldCalls=count($db->events);
if ($oldCalls!==160) throw new RuntimeException("Legacy query witness expected 160, got {$oldCalls}");
$db->events=[];
if (!method_exists(ForumService::class,'postsData')) {
    throw new RuntimeException("RED-before: 40 real post serializers require {$oldCalls} separate DB reads; batch method missing");
}
$actual=ForumService::postsData(collect($posts),$viewer)->all();
if ($actual!==$legacy) throw new RuntimeException('Batch output differs from legacy output');
if (count($db->events)!==4) throw new RuntimeException('Batch path still issues N+1 queries: '.json_encode($db->events));
$checked=count($actual);
foreach ($actual as $index=>$row) {
    $postId=$index+1;
    if ($row['id']!==$postId || $row['reactions_count']!==($postId%2===1?2:1)
        || $row['reacted']!==($postId%2===1)
        || count($row['attachments'])!==($postId%3===0?1:0)) throw new RuntimeException('Wrong row mapping for '.$postId);
}
$db->events=[];
$guestRows=ForumService::postsData(collect($posts),null)->all();
if (count($db->events)!==3 || count($guestRows)!==40) throw new RuntimeException('Guest query/page regression');
foreach($guestRows as $row) if($row['reacted']!==false) throw new RuntimeException('Guest reaction leak');
$db->events=[];
$empty=ForumService::postsData(collect(),$viewer);
if (!$empty->isEmpty() || $db->events!==[]) throw new RuntimeException('Empty page made DB calls');
// Verify the actual topic endpoint uses the batched serializer, not the legacy loop.
$controllerSource=file_get_contents(dirname(__DIR__,2).'/app/Http/Controllers/ForumController.php');
if (!str_contains($controllerSource,'Forum::postsData($posts->getCollection(),$request->user())'))
    throw new RuntimeException('Thread endpoint does not call the batched serializer');
echo "PASS: 40/40 identical post payloads, 160 legacy vs 4 batched reads (3 guest, 0 empty); associations/visibility/controller wiring PASS; fake DB only\n";
