<?php
/**
 * Exercise the ORIGINAL ForumController::bookmark with a post-preflight ban,
 * deletion, and stale thread. The fake DB is NOT an HTTP/PDO integration test.
 */
require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Http\Controllers\ForumController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class BookmarkCompleted extends RuntimeException {
    public function __construct(public array $value) {parent::__construct('bookmark response');}
}
final class BookmarkForumSeam {
    public static function thread(int $id, ?object $actor): object {
        return (object)['id'=>$id,'category_id'=>17];
    }
}
class_alias(BookmarkForumSeam::class,'App\\Services\\ForumService');

final class BookmarkDBSeam {
    public bool $liveUser=true;
    public bool $liveBanned=false;
    public bool $liveThread=true;
    public bool $present=false;
    public int $inserted=0;
    public int $deleted=0;
    public int $guarded=0;
    public function table(string $table): BookmarkQuerySeam {return new BookmarkQuerySeam($this,$table);}
}
final class BookmarkQuerySeam {
    public array $filters=[];
    public array $columns=[];
    public array $joins=[];
    public string $from='';
    public ?string $projection=null;
    public array $bindings=[];
    public bool $protectedDelete=false;
    public function __construct(private BookmarkDBSeam $db, public string $table) {}
    public function where(mixed $key,mixed $value=null,mixed $extra=null): self {
        if (is_array($key)) $this->filters += $key;
        else $this->filters[$key]=$extra??$value;
        return $this;
    }
    public function whereColumn(string $left,string $operator,string|null $right=null): self {
        $this->columns[]=$left.($right===null?'=':$operator).($right??$operator);return $this;
    }
    public function crossJoin(string $table): self {$this->joins[]='cross:'.$table;return $this;}
    public function join(string $table,string $left,string $operator,string $right): self {
        $this->joins[]=$table.':'.$left.$operator.$right;return $this;
    }
    public function from(string $table): self {$this->from=$table;return $this;}
    public function selectRaw(string $text,array $bindings=[]): self {
        $this->projection=$text;$this->bindings=$bindings;return $this;
    }
    public function selectRaw1(string $text): self {$this->projection=$text;return $this;}
    public function whereExists(Closure $fn): self {
        $inner=new self($this->db,'exists');$fn($inner);
        $this->protectedDelete=$inner->from==='threads as t'
            && $inner->joins===['cross:users as actor']
            && in_array('t.id=bookmarks.thread_id',$inner->columns,true)
            && in_array('actor.id=bookmarks.user_id',$inner->columns,true)
            && ($inner->filters['actor.is_banned']??null)===false;
        return $this;
    }
    public function exists(): bool {
        if ($this->table!=='bookmarks') throw new RuntimeException('wrong exists');
        return $this->db->present;
    }
    public function insertOrIgnore(array $payload): int {
        if ($this->table!=='bookmarks') throw new RuntimeException('wrong insert');
        if ($this->db->present) return 0;
        ++$this->db->inserted;$this->db->present=true;return 1;
    }
    public function insertOrIgnoreUsing(array $columns, mixed $q): int {
        if ($this->table!=='bookmarks' || !$q instanceof self
            || $q->table!=='threads as t'
            || $q->joins!==['cross:users as actor']
            || ($q->filters['t.id']??null)!==44
            || ($q->filters['actor.id']??null)!==7
            || ($q->filters['actor.is_banned']??null)!==false
            || $columns!==['thread_id','user_id','created_at','updated_at']
            || !str_contains($q->projection??'', 't.id AS thread_id')
            || !str_contains($q->projection??'', 'actor.id AS user_id')
            || count($q->bindings)!==2)
            throw new RuntimeException('bookmark INSERT missing live authorization');
        ++$this->db->guarded;
        if (!$this->db->liveUser || $this->db->liveBanned || !$this->db->liveThread || $this->db->present) return 0;
        ++$this->db->inserted;$this->db->present=true;return 1;
    }
    public function delete(): int {
        if ($this->table!=='bookmarks') throw new RuntimeException('wrong delete');
        if ($this->protectedDelete) ++$this->db->guarded;
        if (!$this->db->present) return 0;
        if ($this->protectedDelete && (!$this->db->liveUser || $this->db->liveBanned || !$this->db->liveThread)) return 0;
        ++$this->db->deleted;$this->db->present=false;return 1;
    }
}

$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]): never {
        throw new HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator', new class {public function get(string $key): string {return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json($data=[],$status=200,array $headers=[],$options=0): never {
            throw new BookmarkCompleted($data);
        }
    });
$db=new BookmarkDBSeam();DB::swap($db);
$controller=new ForumController();$checks=0;
foreach ([
    [false,true,true,true,403,'ban before bookmark insert'],
    [true,true,true,true,403,'ban before bookmark remove'],
    [false,false,false,true,403,'account removed before insert'],
    [true,false,false,true,403,'account removed before remove'],
    [false,false,true,false,403,'thread removed before insert'],
    [true,false,true,false,403,'thread removed before remove'],
    [false,false,true,true,true,'active member inserts'],
    [true,false,true,true,false,'active member removes'],
] as [$present,$ban,$user,$thread,$expected,$label]) {
    $db->present=$present;$db->liveBanned=$ban;$db->liveUser=$user;$db->liveThread=$thread;
    $db->inserted=0;$db->deleted=0;$db->guarded=0;
    $request=Request::create('/api/threads/44/bookmark','POST');
    $request->setUserResolver(static fn()=>(object)['id'=>7,'role'=>'member','is_banned'=>false]);
    try {$controller->bookmark($request,44);$actual='unexpected success';}
    catch(BookmarkCompleted $response) {$actual=$response->value['bookmarked'];}
    catch(HttpExceptionInterface $error) {$actual=$error->getStatusCode();}
    if ($actual!==$expected) {
        fwrite(STDERR,"RED: $label expected ".json_encode($expected)." actual ".json_encode($actual)
            ."; inserts={$db->inserted} deletes={$db->deleted} guard={$db->guarded}\n");
        exit(1);
    }
    ++$checks;
    $wantInsert=$expected===true?1:0;
    $wantDelete=$expected===false?1:0;
    if ($db->inserted!==$wantInsert || $db->deleted!==$wantDelete) {
        fwrite(STDERR,"FAIL: unwanted mutation $label\n");exit(1);
    }
    $checks+=2;
    if ($db->guarded!==1) {fwrite(STDERR,"FAIL: missing guard $label\n");exit(1);}
    ++$checks;
}
echo "PASS: $checks real-controller bookmark checks with fake DB (NOT PDO/HTTP)\n";
