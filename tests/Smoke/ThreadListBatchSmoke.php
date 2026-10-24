<?php
/**
 * Exercise real thread serializers against an instrumented DB seam.
 * This is not a database/PDO/HTTP or production benchmark.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Services\ForumService;
use Illuminate\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

if (!function_exists('mb_substr')) {function mb_substr(string $v, int $start, ?int $length=null): string {return substr($v, $start, $length ?? strlen($v));}}
if (!function_exists('mb_strlen')) {function mb_strlen(string $v): int {return strlen($v);}}
if (!function_exists('mb_strtoupper')) {function mb_strtoupper(string $v): string {return strtoupper($v);}}

final class ThreadListFakeDatabase
{
    public array $events=[];
    public function __construct(public array $users, public array $categories) {}
    public function table(string $name): ThreadListFakeQuery {return new ThreadListFakeQuery($this,$name);}
}
final class ThreadListFakeQuery
{
    private ?string $whereField=null;
    private ?array $whereIds=null;
    public function __construct(private ThreadListFakeDatabase $db, private string $table) {}
    public function whereIn(string $column, array $ids): self {$this->whereField=$column;$this->whereIds=$ids;return $this;}
    private function rows(): array
    {
        $records=match($this->table) {
            'users'=>$this->db->users,'categories'=>$this->db->categories,
            default=>throw new RuntimeException('Unexpected source: '.$this->table)
        };
        return array_values(array_filter($records,fn($v)=>$this->whereIds===null || in_array($v->{$this->whereField},$this->whereIds,true)));
    }
    public function find(int $id): ?object
    {
        $this->db->events[]=$this->table.'.find';
        foreach ($this->rows() as $v) if ($v->id===$id) return $v;
        return null;
    }
    public function get(): Collection
    {
        $this->db->events[]=$this->table.'.get';
        return collect($this->rows());
    }
}

$app=new Container(); Container::setInstance($app);Facade::setFacadeApplication($app);
$users=[];
for ($i=1;$i<=6;$i++) $users[]=(object)[
    'id'=>$i, 'name'=>'Author '.$i,'role'=>$i%2?'member':'moderator',
    'color'=>'violet','bio'=>'Bio '.$i,'created_at'=>'2026-01-01'
];
$categories=[];
for ($i=1;$i<=3;$i++) $categories[]=(object)[
    'id'=>$i, 'name'=>'Category '.$i,'slug'=>'cat-'.$i,'description'=>'Desc '.$i,
    'color'=>'violet','icon'=>'folder','sort_order'=>$i,'is_private'=>$i===3,
    'parent_id'=>null,'threads_count'=>5
];
$threads=[];
for ($i=1;$i<=15;$i++) $threads[]=(object)[
    'id'=>$i,'title'=>'Thread '.$i,'slug'=>'thread-'.$i,'category_id'=>(($i-1)%3)+1,
    'user_id'=>(($i-1)%6)+1,'is_pinned'=>$i%5===0,'is_locked'=>$i%7===0,
    'views'=>$i*3,'created_at'=>'2026-10-23','updated_at'=>'2026-10-23',
    'posts_count'=>($i%4)+1,'first_body'=>'A <b>short</b> sample body '.$i,
    'reactions_count'=>$i%3,'bookmarked'=>$i%2===0
];
$db=new ThreadListFakeDatabase($users,$categories);DB::swap($db);
$legacy=collect($threads)->map(fn($t)=>ForumService::threadData($t))->all();
$oldCalls=count($db->events);
if ($oldCalls!==30) throw new RuntimeException("Expected 30 original author/category lookups, got {$oldCalls}");
$db->events=[];
if (!method_exists(ForumService::class,'threadsData')) {
    throw new RuntimeException("RED-before: original threadData generated {$oldCalls} individual reads for the 15-thread page; missing batch method");
}
$actual=ForumService::threadsData(collect($threads))->all();
if ($actual!==$legacy) throw new RuntimeException('Batched thread response differs from original serializer');
if ($db->events!==['users.get','categories.get']) throw new RuntimeException('N+1 still present: '.json_encode($db->events));
foreach ($actual as $index=>$row) {
    $t=$threads[$index];
    if ($row['id']!==$t->id || $row['category']['id']!==$t->category_id || $row['author']['id']!==$t->user_id
        || $row['bookmarked']!==$t->bookmarked || $row['replies_count']!==$t->posts_count-1
        || $row['reactions_count']!==$t->reactions_count) throw new RuntimeException('Bad data mapping on thread '.$t->id);
}
$db->events=[];
$empty=ForumService::threadsData(collect());
if (!$empty->isEmpty() || $db->events!==[]) throw new RuntimeException('Empty thread page must not hit DB');
$controller=file_get_contents(dirname(__DIR__,2).'/app/Http/Controllers/ForumController.php');
if (!str_contains($controller,'Forum::threadsData($page->getCollection())')) throw new RuntimeException('API threads endpoint not wired to batched serializer');
echo 'PASS: 15/15 byte-equivalent thread JSON data, original 30 reads vs 2 bulk reads, empty page 0; mapped author/category/counts; controller wiring; fake DB only'.PHP_EOL;
