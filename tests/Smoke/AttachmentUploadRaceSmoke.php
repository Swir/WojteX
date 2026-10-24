<?php
/** Exercise the real AttachmentController::upload with deliberately stale user/thread preflight.
 * Synthetic in-process facade doubles, NOT PDO / multipart HTTP / concurrency evidence.
 */
require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Http\Controllers\AttachmentController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (!function_exists('mb_substr')) {
    // Test-only ASCII name fixture; production still requires real mbstring.
    function mb_substr(string $s, int $start, ?int $len=null): string {return substr($s,$start,$len);}
}
final class UploadAccepted extends RuntimeException {}
final class UploadForumSeam {
    public static function thread(int $id, ?object $actor): object {return (object)['id'=>$id,'category_id'=>3,'is_locked'=>false];}
    public static function canReplyCategory(?object $category,?object $actor): bool {return true;}
    public static function isModerator(?object $actor): bool {return $actor && !$actor->is_banned && in_array($actor->role,['admin','moderator'],true);}
}
class_alias(UploadForumSeam::class,'App\\Services\\ForumService');
final class UploadFileSeam {
    public function getClientOriginalExtension():string{return 'txt';}
    public function getClientOriginalName():string{return 'safe.txt';}
    public function getMimeType():string{return 'text/plain';}
    public function getSize():int{return 42;}
    public function storeAs(string $dir,string $name,string $disk):string{return 'attachments/staged.txt';}
}
final class UploadRequest extends Request {
    public object $actor;
    public function user($guard=null):?object {return $this->actor;}
    public function validate(array $rules,...$args):array{return [];}
    public function file($key=null,$default=null){return new UploadFileSeam();}
}
final class UploadStore {public array $deleted=[];public function delete(string $path):void{$this->deleted[]=$path;}}
final class UploadStorageManager {public function __construct(public UploadStore $store){}public function disk(string $name):UploadStore{return $this->store;}}
final class UploadDb {
    public bool $exists=true,$userExists=true,$ban=false,$locked=false,$explode=false;
    public string $role='member';
    public int $postOwner=7,$preflightCount=1,$currentCount=1,$writes=0,$attempts=0,$guarded=0;
    public function table(string $name):UploadQ{return new UploadQ($this,$name);}
    public function transaction(callable $callback):mixed{return $callback();}
    public function connection():self{return $this;}
    public function getPdo():self{return $this;}
    public function lastInsertId():string{return '99';}
}
final class UploadQ {
    public array $checks=[];private bool $hasOwnerOrStaff=false,$hasLockOrStaff=false,$hasCount=false,$hasJoin=false,$hasActor=false,$hasBan=false;
    public function __construct(public UploadDb $db,public string $name){}
    public function find(int $id):?object{
        if($this->name==='posts')return (object)['id'=>$id,'thread_id'=>5,'user_id'=>$this->db->postOwner];
        if($this->name==='categories')return (object)['id'=>3];
        return null;
    }
    public function where(mixed $field,mixed $operator=null,mixed $value=null):self{
        if (is_callable($field)) {
            $nested=new self($this->db,'nested');$field($nested);
            if($nested->hasOwnerOrStaff)$this->hasOwnerOrStaff=true;
            if($nested->hasLockOrStaff)$this->hasLockOrStaff=true;
            return $this;
        }
        $v=$value===null?$operator:$value;
        $this->checks[$field]=$v;
        if($field==='actor.id' && $v===7)$this->hasActor=true;
        if($field==='actor.is_banned' && $v===false)$this->hasBan=true;
        if($field==='t.is_locked' && $v===false)$this->hasLockOrStaff=true;
        return $this;
    }
    public function join(string $table,string $from,string $op,string $to):self{
        if($table==='threads as t')$this->hasJoin=true;
        return $this;
    }
    public function crossJoin(string $table):self{return $this;}
    public function whereColumn(string $left,string $right):self{
        if($left==='p.user_id'&&$right==='actor.id')$this->hasOwnerOrStaff=true;
        return $this;
    }
    public function orWhereIn(string $field,array $roles):self{
        if($roles!==['admin','moderator'])throw new RuntimeException('staff list changed');
        if($field==='actor.role'){
            if($this->hasOwnerOrStaff)$this->hasOwnerOrStaff=true;
            else $this->hasLockOrStaff=true;
        }
        return $this;
    }
    public function whereRaw(string $sql,array $bindings=[]):self{
        if(str_contains($sql,'COUNT(*) FROM attachments') && str_contains($sql,'< 5'))$this->hasCount=true;
        return $this;
    }
    public function selectRaw(string $sql,array $bindings=[]):self{return $this;}
    public function lockForUpdate():self{return $this;}
    public function first():?object{
        return $this->db->exists?(object)['id'=>5,'thread_id'=>5,'user_id'=>$this->db->postOwner]:null;
    }
    public function count():int{return $this->db->preflightCount;}
    public function insertGetId(array $record):int{++$this->db->writes;return 99;}
    public function insertUsing(array $columns,object $select):int{
        ++$this->db->attempts;
        if($this->db->explode)throw new RuntimeException('injected db failure');
        $guard=$select instanceof self && $select->hasJoin && $select->hasActor && $select->hasBan && $select->hasCount && $select->hasOwnerOrStaff && $select->hasLockOrStaff
            && ($select->checks['p.id']??null)===5 && ($select->checks['p.thread_id']??null)===5;
        if($guard)++$this->db->guarded;
        $authorized=(!$guard || ($this->db->exists && $this->db->userExists && !$this->db->ban
           && ($this->db->postOwner===7 || in_array($this->db->role,['admin','moderator'],true))
           && (!$this->db->locked || in_array($this->db->role,['admin','moderator'],true))
           && $this->db->currentCount<5));
        if(!$authorized)return 0;
        ++$this->db->writes;
        return 1;
    }
    public function __call(string $method,array $args):self{
        if($method==='where' && is_callable($args[0])){
            $nested=new self($this->db,'nested');$args[0]($nested);
            if($nested->hasOwnerOrStaff)$this->hasOwnerOrStaff=true;
            if($nested->hasLockOrStaff)$this->hasLockOrStaff=true;
            return $this;
        }
        throw new RuntimeException('unknown query operation '.$method);
    }
}
// Laravel Query Builder closure handling on real query; on the fake, invoke nested closures.
// __call cannot capture where() because it exists: handled by where() itself below.
$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]):never{
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,$message);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key):string{return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,new class extends \Illuminate\Routing\ResponseFactory {
    public function __construct(){}
    public function json($data=[],$status=200,array $headers=[],$options=0) {throw new UploadAccepted();}
});
$db=new UploadDb(); DB::swap($db);
$store=new UploadStore();Storage::swap(new UploadStorageManager($store));
$controller=new AttachmentController();
$checks=0;
$scenarios=[
    ['banned between preflight and attachment INSERT', 'member', 'member', 7, true, false, 1, 1, true, 403],
    ['deleted user between preflight and INSERT', 'member', 'member', 7, false, false, 1, 1, true, 403],
    ['deleted post after file staging', 'member', 'member', 7, false, false, 1, 1, false, 404],
    ['staff demoted between preflight and INSERT', 'moderator', 'member', 8, false, false, 1, 1, true, 403],
    ['locked thread after preflight', 'member', 'member', 7, false, true, 1, 1, true, 403],
    ['concurrent fifth attachment', 'member', 'member', 7, false, false, 4, 5, true, 403],
    ['active author', 'member', 'member', 7, false, false, 1, 1, true, 'ok'],
    ['active moderator on locked topic', 'moderator', 'moderator', 8, false, true, 1, 1, true, 'ok'],
];
foreach($scenarios as [$label,$cached,$live,$owner,$banned,$locked,$beforeCount,$currentCount,$exists,$expected]){
    $db->role=$live;$db->postOwner=$owner;$db->ban=$banned;$db->locked=$locked;$db->preflightCount=$beforeCount;
    $db->currentCount=$currentCount;$db->exists=$exists;$db->userExists=$label!=='deleted user between preflight and INSERT';
    $db->writes=$db->attempts=$db->guarded=0;$db->explode=false;$store->deleted=[];
    $req=new UploadRequest();$req->actor=(object)['id'=>7,'role'=>$cached,'is_banned'=>false];
    try{$controller->upload($req,5);$got='returned';}
    catch(UploadAccepted){$got='ok';}
    catch(HttpExceptionInterface $e){$got=$e->getStatusCode();}
    if($got!==$expected)throw new RuntimeException("$label expected $expected got $got; writes={$db->writes}, attempts={$db->attempts}, deleted=".count($store->deleted));++$checks;
    if($db->writes!==($expected==='ok'?1:0))throw new RuntimeException("$label unexpected DB writes {$db->writes}");++$checks;
    if($store->deleted!==($expected==='ok'?[]:['attachments/staged.txt']))throw new RuntimeException("$label staged file not cleaned");++$checks;
    if($db->guarded!==1 && $exists)throw new RuntimeException("$label lacked guarded INSERT");++$checks;
}
echo "PASS: $checks deterministic assertions on real attachment controller with synthetic DB/storage seams (NOT PHP PDO/HTTP)\n";
