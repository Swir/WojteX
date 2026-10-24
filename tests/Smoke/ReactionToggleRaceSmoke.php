<?php
/**
 * Exercise ACTUAL ForumController::reaction with a deterministic DB/storage
 * seam. Change live account/ownership after controller preflight; NOT HTTP/PDO.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\ForumController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ReactionCompleted extends RuntimeException {
    public function __construct(public array $data) {parent::__construct('reaction returned');}
}
final class ReactionForumSeam {
    public static function thread(int $id, ?object $actor): object {
        return (object)['id'=>$id, 'category_id'=>17, 'is_locked'=>false];
    }
    public static function canReplyCategory(object $category, ?object $actor): bool {
        return $actor !== null && !$actor->is_banned;
    }
}
class_alias(ReactionForumSeam::class, 'App\\Services\\ForumService');
final class ReactionDBSeam {
    public bool $liveUser = true;
    public bool $livePost = true;
    public bool $liveThread = true;
    public bool $liveBanned = false;
    public int $livePostOwner = 8;
    public int $cachedPostOwner = 8;
    public bool $existingReaction = false;
    public int $actualInserts = 0;
    public int $actualDeletes = 0;
    public int $guardedWrites = 0;
    public function table(string $source): ReactionQuerySeam {return new ReactionQuerySeam($this, $source);}
}
final class ReactionQuerySeam {
    public array $clauses = [];
    public array $columns = [];
    public array $joins = [];
    public array $bindings = [];
    public string $source = '';
    public ?string $projection = null;
    public bool $guardedDelete = false;
    public function __construct(private ReactionDBSeam $db, public string $table) {}
    public function find(int $id): ?object {
        if ($this->table === 'posts') return (object)['id'=>$id,'user_id'=>$this->db->cachedPostOwner,'thread_id'=>19];
        if ($this->table === 'categories') return (object)['id'=>$id,'is_private'=>false];
        throw new RuntimeException('unexpected find '.$this->table);
    }
    public function where(mixed $field, mixed $value=null, mixed $third=null): self {
        if ($field instanceof Closure) {$field($this);return $this;}
        if (is_array($field)) $this->clauses += $field;
        else $this->clauses[$field]=$third??$value;
        return $this;
    }
    public function whereColumn(string $first, string $operator, ?string $second=null): self {
        $this->columns[]=$first.($second===null?'=':$operator).($second??$operator);return $this;
    }
    public function join(string $table, string $left, string $operator, string $right): self {
        $this->joins[]=$table.':'.$left.$operator.$right;return $this;
    }
    public function crossJoin(string $table): self {$this->joins[]='cross:'.$table;return $this;}
    public function from(string $source): self {$this->source=$source;return $this;}
    public function selectRaw(string $selection, array $bindings=[]): self {
        $this->projection=$selection;$this->bindings=$bindings;return $this;
    }
    public function whereExists(Closure $cb): self {
        $nested=new self($this->db, 'exists');$cb($nested);
        $this->guardedDelete=$nested->source==='posts as p'
            && $nested->joins===['threads as t:t.id=p.thread_id','cross:users as actor']
            && in_array('p.id=reactions.post_id',$nested->columns,true)
            && in_array('actor.id=reactions.user_id',$nested->columns,true)
            && in_array('p.user_id<>actor.id',$nested->columns,true)
            && ($nested->clauses['actor.is_banned']??null)===false;
        return $this;
    }
    public function exists(): bool {
        if ($this->table!=='reactions') throw new RuntimeException('unexpected exists');
        return $this->db->existingReaction;
    }
    public function count(): int {
        if ($this->table!=='reactions') throw new RuntimeException('unexpected count');
        return (int)$this->db->existingReaction;
    }
    public function insertOrIgnore(array $values): int {
        if ($this->table!=='reactions') throw new RuntimeException('unexpected insert');
        if ($this->db->existingReaction) return 0;
        ++$this->db->actualInserts;$this->db->existingReaction=true;return 1;
    }
    public function insertOrIgnoreUsing(array $columns, mixed $candidate): int {
        if ($this->table!=='reactions' || !$candidate instanceof self
            || $candidate->table!=='posts as p'
            || $candidate->joins!==['threads as t:t.id=p.thread_id','cross:users as actor']
            || $columns!==['post_id','user_id','created_at','updated_at']
            || ($candidate->clauses['p.id']??null)!==44
            || ($candidate->clauses['actor.id']??null)!==7
            || ($candidate->clauses['actor.is_banned']??null)!==false
            || !in_array('p.user_id<>actor.id',$candidate->columns,true)
            || !str_contains($candidate->projection??'', 'p.id AS post_id')
            || !str_contains($candidate->projection??'', 'actor.id AS user_id')
            || count($candidate->bindings)!==2)
            throw new RuntimeException('Reaction INSERT missing live guards');
        ++$this->db->guardedWrites;
        if (!$this->db->liveUser || !$this->db->livePost || !$this->db->liveThread
            || $this->db->liveBanned || $this->db->livePostOwner===7 || $this->db->existingReaction) return 0;
        ++$this->db->actualInserts;$this->db->existingReaction=true;return 1;
    }
    public function delete(): int {
        if ($this->table!=='reactions') throw new RuntimeException('unexpected delete');
        if ($this->guardedDelete) ++$this->db->guardedWrites;
        $authorized = !$this->guardedDelete || ($this->db->liveUser && $this->db->livePost
            && $this->db->liveThread && !$this->db->liveBanned && $this->db->livePostOwner!==7);
        if (!$this->db->existingReaction || !$authorized) return 0;
        ++$this->db->actualDeletes;$this->db->existingReaction=false;return 1;
    }
}
$app = new class extends Container {
    public function abort(int $code, string $message='', array $headers=[]): never {
        throw new HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator', new class {public function get(string $key): string {return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json($data=[], $status=200, array $headers=[], $options=0): never {
            throw new ReactionCompleted($data);
        }
    });
$db = new ReactionDBSeam();DB::swap($db);
$controller = new ForumController();$checks = 0;
foreach ([
    // pre-existing reaction, current ban, current owner, live user/post/thread, expected, label, cached owner
    [false,true,8,true,true,true,403,'ban between preflight and reaction INSERT',8],
    [true,true,8,true,true,true,403,'ban between preflight and reaction DELETE',8],
    [false,false,8,true,true,true,'reacted','active member inserts reaction',8],
    [true,false,8,true,true,true,'unreacted','active member removes reaction',8],
    [false,false,7,true,true,true,403,'post ownership switched before insert',8],
    [true,false,7,true,true,true,403,'post ownership switched before remove',8],
    [false,false,8,false,true,true,403,'deleted user before insert',8],
    [false,false,8,true,false,true,403,'deleted post before insert',8],
    [false,false,8,true,true,false,403,'deleted thread before insert',8],
    [false,false,8,true,true,true,422,'self reaction denied at preflight',7],
] as [$had,$banned,$owner,$userExists,$postExists,$threadExists,$expected,$label,$cachedOwner]) {
    $db->existingReaction=$had;$db->liveBanned=$banned;$db->livePostOwner=$owner;
    $db->liveUser=$userExists;$db->livePost=$postExists;$db->liveThread=$threadExists;
    $db->cachedPostOwner=$cachedOwner;$db->actualInserts=0;$db->actualDeletes=0;$db->guardedWrites=0;
    $request=Request::create('/api/posts/44/reaction','POST');
    $request->setUserResolver(static fn()=>(object)['id'=>7,'role'=>'member','is_banned'=>false]);
    try {$controller->reaction($request,44);$actual='unexpected success';}
    catch(ReactionCompleted $result){$actual=$result->data['reacted']?'reacted':'unreacted';}
    catch(HttpExceptionInterface $error){$actual=$error->getStatusCode();}
    if ($actual!==$expected) {
        fwrite(STDERR,"RED: $label expected ".json_encode($expected)." actual ".json_encode($actual)
            ."; inserts={$db->actualInserts}, deletes={$db->actualDeletes}\n");exit(1);
    }
    ++$checks;
    $wantInsert=$expected==='reacted'?1:0;
    $wantDelete=$expected==='unreacted'?1:0;
    if ($db->actualInserts!==$wantInsert || $db->actualDeletes!==$wantDelete) {
        fwrite(STDERR,"FAIL: unauthorized write on $label\n");exit(1);
    }
    $checks+=2;
    if ($expected!==422 && $db->guardedWrites!==1) {
        fwrite(STDERR,"FAIL: missing SQL eligibility on $label\n");exit(1);
    }
    ++$checks;
}
echo "PASS: $checks real ForumController reaction assertions (fake DB, NOT PHP PDO/HTTP)\n";
