<?php
/**
 * Actual ForumController::deletePost against an isolated deterministic DB seam.
 * Cached request user remains authorized; simulated DB account/lock state changes.
 * Not an HTTP, PDO, or native concurrency test.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\ForumController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PostDeleteCommitted extends RuntimeException {}
final class PostDeleteForumSeam {
    public static int $audit=0;
    public static bool $cachedLocked=false;
    public static function thread(int $id, ?object $actor): object {
        return (object)['id'=>$id,'category_id'=>17,'is_locked'=>self::$cachedLocked];
    }
    public static function canReplyCategory(?object $category, ?object $actor): bool {
        return $category !== null && $actor !== null && !$actor->is_banned;
    }
    public static function isModerator(?object $actor): bool {
        return $actor !== null && !$actor->is_banned && in_array($actor->role,['admin','moderator'],true);
    }
    public static function audit(string $event, array $detail): void {
        if ($event!=='post.deleted' || ($detail['post_id']??null)!==88) throw new RuntimeException('invalid audit');
        ++self::$audit;
    }
}
class_alias(PostDeleteForumSeam::class,'App\\Services\\ForumService');
final class PostDeleteFakeDB {
    public bool $userExists=true;
    public bool $liveBanned=false;
    public string $liveRole='member';
    public bool $threadExists=true;
    public bool $liveLocked=false;
    public bool $postExists=true;
    public int $postOwner=7;
    public int $firstId=87;
    public int $deleted=0;
    public int $guarded=0;
    public int $threadLocks=0;
    public function table(string $name): PostDeleteFakeQuery {return new PostDeleteFakeQuery($this,$name);}
    public function transaction(callable $cb): mixed {return $cb();}
}
final class PostDeleteFakeQuery {
    private string $from='';
    private string $join='';
    private bool $role=false;
    private bool $owner=false;
    private bool $lock=false;
    private bool $guard=false;
    private bool $hasFirstGuard=false;
    private array $conds=[];
    public function __construct(private PostDeleteFakeDB $db, private string $table) {}
    public function find(int $id): ?object {
        if ($this->table==='posts') return $this->db->postExists ? (object)['id'=>88,'thread_id'=>33,'user_id'=>$this->db->postOwner] : null;
        if ($this->table==='categories') return (object)['id'=>17,'is_private'=>false];
        throw new RuntimeException('unknown find '.$this->table);
    }
    public function where(mixed $column, mixed $operator=null, mixed $value=null): self {
        if (is_callable($column)) {$column($this);return $this;}
        $actual=func_num_args()===3?$value:$operator;
        $this->conds[(string)$column]=$actual;
        if ($column==='live_thread.is_locked' && $actual===false) $this->lock=true;
        if ($column==='id' && $operator==='!=' && $actual===87) $this->hasFirstGuard=true;
        return $this;
    }
    public function selectRaw(string $raw): self {return $this;}
    public function from(string $table): self {$this->from=$table;return $this;}
    public function join(string $table, string $left, string $operator, string $right): self {
        $this->join=$table.':'.$left.$operator.$right;return $this;
    }
    public function whereIn(string $field, array $values): self {
        if ($field==='live_deleter.role' && in_array('admin',$values,true) && in_array('moderator',$values,true)) $this->role=true;
        return $this;
    }
    public function whereColumn(string $left, string $right): self {
        if ($left==='posts.user_id' && $right==='live_deleter.id') $this->owner=true;
        return $this;
    }
    public function orWhere(callable $cb): self {$cb($this);return $this;}
    public function whereExists(callable $cb): self {
        $nested=new self($this->db,'exists');$cb($nested);
        $this->guard=($nested->from==='users as live_deleter'
            && $nested->join==='threads as live_thread:live_thread.id=posts.thread_id'
            && ($nested->conds['live_deleter.id']??null)===7
            && ($nested->conds['live_deleter.is_banned']??null)===false
            && $nested->role && $nested->owner && $nested->lock);
        return $this;
    }
    public function lockForUpdate(): self {
        if ($this->table==='threads') ++$this->db->threadLocks;
        return $this;
    }
    public function first(): ?object {
        if ($this->table==='threads') return $this->db->threadExists ? (object)['id'=>33] : null;
        if ($this->table==='posts') return $this->db->postExists ? (object)['id'=>88,'thread_id'=>33,'user_id'=>$this->db->postOwner] : null;
        throw new RuntimeException('unexpected first '.$this->table);
    }
    public function min(string $column): int {if ($this->table!=='posts'||$column!=='id') throw new RuntimeException('unexpected min');return $this->db->firstId;}
    public function pluck(string $column): object {
        if ($this->table!=='attachments' || $column!=='path') throw new RuntimeException('unexpected pluck');
        return new class {public function all():array{return ['private/fake-attachment'];}};
    }
    public function delete(): int {
        if ($this->table!=='posts') throw new RuntimeException('unexpected delete '.$this->table);
        $guarded=$this->guard && $this->hasFirstGuard && ($this->conds['id']??null)===87 && ($this->conds['thread_id']??null)===33;
        // id=88 normally also appears; the '!=' overwrite in fake where() makes 87 marker.
        // The actual compiled SQL gets independent predicates for target and first.
        if ($guarded) ++$this->db->guarded;
        $authorized = !$guarded || ($this->db->postExists && $this->db->threadExists
            && $this->db->userExists && !$this->db->liveBanned
            && $this->db->firstId!==88
            && (in_array($this->db->liveRole,['admin','moderator'],true)
                || ($this->db->postOwner===7 && !$this->db->liveLocked)));
        if (!$authorized) return 0;
        ++$this->db->deleted;return 1;
    }
}
final class PostDeleteDisk {public static int $deleted=0;public function delete(array $paths): never {self::$deleted+=count($paths);throw new PostDeleteCommitted();}}
final class PostDeleteStore {public function disk(string $name):PostDeleteDisk {if ($name!=='local') throw new RuntimeException('disk');return new PostDeleteDisk();}}
$app=new class extends Container {public function abort(int $code,string $message='',array $headers=[]):never{throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,$message,null,$headers);}};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class{public function get(string $key):string{return $key;}});
$db=new PostDeleteFakeDB(); DB::swap($db); Storage::swap(new PostDeleteStore());
$controller=new ForumController();$assertions=0;
foreach ([
    // cache role, current role, owner, banned, live locked, exists user/thread/post, current first, expected, label
    ['member','member',7,true,false,true,true,true,87,403,'banned owner after preflight'],
    ['member','member',7,false,false,true,true,true,87,'deleted','active owner'],
    ['member','member',7,false,true,true,true,true,87,403,'thread locked after preflight'],
    ['moderator','member',99,false,false,true,true,true,87,403,'demoted moderator'],
    ['moderator','moderator',99,false,true,true,true,true,87,'deleted','active moderator on locked thread'],
    ['admin','admin',99,false,true,true,true,true,87,'deleted','active administrator'],
    ['moderator','moderator',99,true,false,true,true,true,87,403,'banned staff'],
    ['member','member',7,false,false,false,true,true,87,403,'actor account deleted'],
    ['member','member',7,false,false,true,false,true,87,404,'thread deleted'],
    ['member','member',7,false,false,true,true,false,87,404,'post deleted'],
    ['member','member',7,false,false,true,true,true,88,422,'original first post protected'],
    ['member','member',99,false,false,true,true,true,87,403,'non owner blocked at preflight'],
] as [$cached,$current,$owner,$banned,$locked,$userExists,$threadExists,$postExists,$first,$expected,$label]) {
    $db->liveRole=$current;$db->postOwner=$owner;$db->liveBanned=$banned;$db->liveLocked=$locked;
    $db->userExists=$userExists;$db->threadExists=$threadExists;$db->postExists=$postExists;
    $db->firstId=$first;$db->deleted=0;$db->guarded=0;$db->threadLocks=0;
    PostDeleteForumSeam::$audit=0;PostDeleteDisk::$deleted=0;
    PostDeleteForumSeam::$cachedLocked=false;
    $request=Request::create('/api/posts/88','DELETE');
    $request->setUserResolver(static fn()=>(object)['id'=>7,'is_banned'=>false,'role'=>$cached]);
    try {$controller->deletePost($request,88);$actual='returned';}
    catch(PostDeleteCommitted){$actual='deleted';}
    catch(HttpExceptionInterface $exception){$actual=$exception->getStatusCode();}
    if ($actual!==$expected) {
        fwrite(STDERR,"FAIL $label expected ".json_encode($expected)." got ".json_encode($actual)." (deleted={$db->deleted}, audit=".PostDeleteForumSeam::$audit.")\n");exit(1);
    }
    ++$assertions;
    $shouldWrite=$expected==='deleted'?1:0;
    if ($db->deleted!==$shouldWrite || PostDeleteForumSeam::$audit!==$shouldWrite || PostDeleteDisk::$deleted!==$shouldWrite) {
        fwrite(STDERR,"FAIL $label side effects: deleted={$db->deleted}, audit=".PostDeleteForumSeam::$audit.", files=".PostDeleteDisk::$deleted."\n");exit(1);
    }
    $assertions+=3;
    if (!in_array($label,['non owner blocked at preflight','post deleted'],true)) {
        if ($db->threadLocks!==1) {fwrite(STDERR,"FAIL $label missing thread row lock\n");exit(1);}
        ++$assertions;
    }
    if (!in_array($label,['non owner blocked at preflight','post deleted','thread deleted','original first post protected'],true)) {
        if ($db->guarded!==1) {fwrite(STDERR,"FAIL $label no current authorization guard\n");exit(1);}
        ++$assertions;
    }
}
echo "PASS: $assertions actual ForumController assertions with fake DB (NOT PDO/HTTP)\n";
