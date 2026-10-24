<?php
/** Exercise REAL ForumController::reply through a deterministic fake DB seam.
 * Not a concurrent HTTP/PDO test; persisted status is varied after preflight. */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\ForumController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ReplySuccess extends RuntimeException {}
class ReplyForumSeam {
    public static bool $cachedLock = false;
    public static function thread(int $id, ?object $user): object {return (object)['id'=>$id,'category_id'=>5,'is_locked'=>self::$cachedLock];}
    public static function canReplyCategory(object $category, ?object $user): bool {return $user !== null && !$user->is_banned;}
    public static function isModerator(?object $user): bool {return $user && !$user->is_banned && in_array($user->role,['admin','moderator'],true);}
}
class_alias(ReplyForumSeam::class,'App\\Services\\ForumService');
class ReplyDbSeam {
    public bool $exists=true;
    public bool $currentBan=false;
    public bool $currentLocked=false;
    public string $currentRole='member';
    public int $postWrites=0;
    public int $threadUpdates=0;
    public int $guarded=0;
    public int $attempts=0;
    public function transaction(callable $cb): mixed {return $cb();}
    public function table(string $table): ReplyQuerySeam {return new ReplyQuerySeam($this,$table);}
    public function connection(): object {return new class { public function getPdo(): object {return new class {public function lastInsertId(): string {return '99';}};}};}
}
class ReplyQuerySeam {
    public array $where=[];
    public array $joins=[];
    public bool $roleGuard=false;
    public bool $lockGuard=false;
    public array $bindings=[];
    public ?string $selection=null;
    public function __construct(private ReplyDbSeam $db, public string $table) {}
    public function find(int $id): object {if($this->table!=='categories') throw new RuntimeException('Unexpected find');return (object)['id'=>$id,'is_private'=>false];}
    public function crossJoin(string $table): self {$this->joins[]=$table;return $this;}
    public function where(string|Closure $column, mixed $a=null, mixed $b=null): self {
        if ($column instanceof Closure) { $column($this);return $this;}
        $value=$b===null?$a:$b;$this->where[$column]=$value;
        if ($column==='t.is_locked' && $value===false) $this->lockGuard=true;
        return $this;
    }
    public function orWhereIn(string $column, array $values): self {if($column==='u.role' && $values===['admin','moderator'])$this->roleGuard=true;return $this;}
    public function selectRaw(string $sql,array $bindings=[]): self {$this->selection=$sql;$this->bindings=$bindings;return $this;}
    public function insertGetId(array $row): int {
        if ($this->table !== 'posts') throw new RuntimeException('Unexpected insertGetId');
        ++$this->db->postWrites;++$this->db->attempts;
        return 99;
    }
    public function insertUsing(array $columns,mixed $candidate): int {
        if ($this->table!=='posts' || !$candidate instanceof self || $candidate->table!=='threads as t'
            || $candidate->joins!==['users as u']
            || $columns!==['thread_id','user_id','body','body_format','created_at','updated_at']
            || $candidate->where['t.id']!==42 || $candidate->where['u.id']!==7
            || $candidate->where['u.is_banned']!==false
            || !$candidate->lockGuard || !$candidate->roleGuard
            || !str_contains($candidate->selection??'','t.id AS thread_id')
            || !str_contains($candidate->selection??'','u.id AS user_id')
            || $candidate->bindings[0]!=='Correct reply body'
            || $candidate->bindings[1]!=='markdown') throw new RuntimeException('Missing guarded INSERT');
        ++$this->db->guarded;++$this->db->attempts;
        if (!$this->db->exists || $this->db->currentBan || ($this->db->currentLocked && !in_array($this->db->currentRole,['admin','moderator'],true))) return 0;
        ++$this->db->postWrites;return 1;
    }
    public function update(array $data): never {
        if ($this->table!=='threads' || $this->where['id']!==42 || !array_key_exists('updated_at',$data)) throw new RuntimeException('Unexpected update');
        ++$this->db->threadUpdates;throw new ReplySuccess();
    }
}
class ReplyRequest extends Request {public function validate(array $rules): array {return ['body'=>'Correct reply body'];}}
$app=new class extends Container {public function abort(int $code,string $message='',array $headers=[]): never {throw new HttpException($code,$message,null,$headers);}};
Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string {return $key;}});
$db=new ReplyDbSeam();DB::swap($db);$controller=new ForumController();$checks=0;
foreach([
    ['member',false,false,'member',true,false,false,403,'banned between preflight and insert'],
    ['member',false,true,'member',false,true,true,403,'thread locked between preflight and insert'],
    ['moderator',false,true,'member',false,true,true,403,'demoted moderator on locked thread'],
    ['moderator',false,true,'moderator',false,true,true,'written','still-authorized moderator may reply to locked thread'],
    ['admin',false,true,'admin',false,true,true,'written','admin may reply to locked thread'],
    ['member',false,false,'member',false,false,true,'written','active member replies to unlocked thread'],
    ['member',false,false,'member',false,false,false,403,'account removed after preflight'],
    ['member',false,false,'member',false,true,true,403,'cached unlocked thread now locked'],
    ['member',false,false,'member',false,false,true,403,'cached locked thread preflight denies',true],
] as $test) {
    [$cachedRole,$cachedBan,$cachedModerator,$currentRole,$currentBan,$locked,$exists,$expected,$label]=$test;
    $cachedLock=$test[9]??false;
    ReplyForumSeam::$cachedLock=$cachedLock;
    $db->exists=$exists;$db->currentBan=$currentBan;$db->currentRole=$currentRole;$db->currentLocked=$locked;
    $db->postWrites=0;$db->threadUpdates=0;$db->guarded=0;$db->attempts=0;
    $request=ReplyRequest::create('/api/threads/42/posts','POST');
    $request->setUserResolver(static fn()=>(object)['id'=>7,'role'=>$cachedRole,'is_banned'=>$cachedBan]);
    try {$controller->reply($request,42);$actual='unexpected success';}
    catch(ReplySuccess){$actual='written';}
    catch(HttpExceptionInterface $e){$actual=$e->getStatusCode();}
    if ($actual!==$expected) {fwrite(STDERR,"RED: $label expected ".json_encode($expected)." actual ".json_encode($actual)."; postWrites={$db->postWrites}, threadUpdates={$db->threadUpdates}\n");exit(1);}
    ++$checks;
    $want=$expected==='written'?1:0;
    if ($db->postWrites!==$want || $db->threadUpdates!==$want) {fwrite(STDERR,"BAD state: $label\n");exit(1);}
    $checks+=2;
    if (!$cachedLock && !$db->guarded) {fwrite(STDERR,"Unprotected INSERT on $label\n");exit(1);}
    ++$checks;
}
echo "PASS: $checks controller assertions with fake DB (NOT PHP PDO/HTTP)\n";
