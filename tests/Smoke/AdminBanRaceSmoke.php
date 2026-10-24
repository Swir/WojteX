<?php
/**
 * Exercise the ACTUAL AdminController::user with a cached actor/target and an
 * independently mutable current user table. Fake DB/model, NOT HTTP/PDO.
 * Run against the unchanged predecessor too: it must reject this test.
 */
require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Http\Controllers\AdminController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AdminBanResponse extends RuntimeException {}
final class BanState {
    /** @var array<int,array{role:string,is_banned:bool}> */
    public static array $people=[];
    public static string $cachedTargetRole='member';
    public static int $reads=0;
    public static int $writes=0;
    public static int $attempts=0;
    public static bool $simulateMySqlZero=false;
}
final class BanUser {
    public function __construct(public int $id,public string $role,public bool $is_banned) {}
    public static function findOrFail(int $id): self {
        ++BanState::$reads;
        if (!isset(BanState::$people[$id])) throw new HttpException(404);
        $row=BanState::$people[$id];
        // Original first read is stale by the time the UPDATE executes.
        $role=BanState::$reads===1 && $id===2 ? BanState::$cachedTargetRole : $row['role'];
        return new self($id,$role,$row['is_banned']);
    }
    public function save(): bool {
        // Legacy unguarded Eloquent save succeeds, even for newly promoted admins.
        if (!isset(BanState::$people[$this->id])) return false;
        BanState::$people[$this->id]['is_banned']=$this->is_banned;
        ++BanState::$writes;
        return true;
    }
}
class_alias(BanUser::class,'App\\Models\\User');
final class BanForum {
    public static int $audit=0;
    public static function publicUser(object $user): array {return ['id'=>$user->id,'role'=>$user->role];}
    public static function audit(string $action,array $details): void {
        if ($action!=='user.ban.updated' || $details['user_id']!==2) throw new RuntimeException('wrong audit');
        ++self::$audit;
    }
}
class_alias(BanForum::class,'App\\Services\\ForumService');
final class BanDB {
    public function connection(): self {return $this;}
    public function getDriverName(): string {return 'sqlite';}
    public function table(string $table): BanQuery {return new BanQuery($table);}
}
final class BanQuery {
    private array $predicates=[];
    private array $existence=[];
    private ?string $from=null;
    public function __construct(private string $table) {}
    public function where(string $column,mixed $operator=null,mixed $value=null): self {
        if ($value===null) {$value=$operator;$operator='=';}
        $this->predicates[]=[$column,$operator,$value];
        return $this;
    }
    public function from(string $table): self {$this->from=$table;return $this;}
    public function selectRaw(string $expression): self {return $this;}
    public function whereExists(callable $callback): self {
        $nested=new self('exists');$callback($nested);$this->existence[]=$nested;return $this;
    }
    private function matchesRow(array $row,int $id): bool {
        foreach ($this->predicates as [$column,$operator,$expect]) {
            $name=substr($column,strrpos($column,'.')===false?0:strrpos($column,'.')+1);
            $actual=$name==='id'?$id:($row[$name]??null);
            $ok=$operator==='!=' ? $actual!=$expect : $actual==$expect;
            if (!$ok) return false;
        }
        foreach ($this->existence as $nested) {
            // This is intentionally a faithful 2nd-row guard, not a stub.
            if ($nested->from!=='users as current_admin') throw new RuntimeException('unexpected exists table');
            $found=false;
            foreach (BanState::$people as $uid=>$person) {
                if ($nested->matchesRow($person,(int)$uid)) {$found=true;break;}
            }
            if (!$found) return false;
        }
        return true;
    }
    private function matchedTarget(): ?int {
        if ($this->table!=='users') throw new RuntimeException('unexpected mutation table');
        foreach (BanState::$people as $id=>$row) if ($this->matchesRow($row,$id)) return (int)$id;
        return null;
    }
    public function exists(): bool {return $this->matchedTarget()!==null;}
    public function update(array $data): int {
        ++BanState::$attempts;
        if (array_keys($data)!==['is_banned','updated_at']) throw new RuntimeException('unexpected columns');
        $id=$this->matchedTarget();if ($id===null) return 0;
        if (BanState::$simulateMySqlZero && BanState::$people[$id]['is_banned']===(bool)$data['is_banned']) return 0;
        BanState::$people[$id]['is_banned']=(bool)$data['is_banned'];++BanState::$writes;
        return 1;
    }
}
final class BanRequest extends Request {
    public function validate(array $rules): array {return ['is_banned'=>true];}
}
$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]): never {
        throw new HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string{return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,new class extends \Illuminate\Routing\ResponseFactory {
    public function __construct() {}
    public function json($data=[], $status=200,array $headers=[], $options=0) {throw new AdminBanResponse('ok');}
});
DB::swap(new BanDB());
$controller=new AdminController();$assertions=0;
$cases=[
    // label,current actor role,banned/exists,current target role,banned/exists,expected,writes,audits
    ['target promoted after preflight','admin',false,true,'admin',false,true,403,0,0],
    ['actor demoted after middleware','moderator',false,true,'member',false,true,403,0,0],
    ['actor banned after middleware','admin',true,true,'member',false,true,403,0,0],
    ['actor deleted after middleware','admin',false,false,'member',false,true,403,0,0],
    ['target removed after preflight','admin',false,true,'member',false,false,404,0,0],
    ['active admin bans ordinary account','admin',false,true,'member',false,true,'ok',1,1],
    ['active admin bans moderator','admin',false,true,'moderator',false,true,'ok',1,1],
    ['already banned no-op','admin',false,true,'member',true,true,'ok',0,0],
];
foreach ($cases as [$label,$actorRole,$actorBanned,$actorExists,$targetRole,$targetBanned,$targetExists,$expected,$writes,$audits]) {
    BanState::$people=[];
    if($actorExists) BanState::$people[1]=['role'=>$actorRole,'is_banned'=>$actorBanned];
    if($targetExists) BanState::$people[2]=['role'=>$targetRole,'is_banned'=>$targetBanned];
    BanState::$cachedTargetRole='member';BanState::$reads=BanState::$writes=BanState::$attempts=0;
    BanState::$simulateMySqlZero=($label==='already banned no-op');BanForum::$audit=0;
    $request=BanRequest::create('/api/admin/users/2','PATCH');
    $request->setUserResolver(static fn()=>new BanUser(1,'admin',false));
    try {$controller->user($request,2);$actual='returned';}
    catch(AdminBanResponse) {$actual='ok';}
    catch(HttpExceptionInterface $ex) {$actual=$ex->getStatusCode();}
    if ($actual!==$expected) {fwrite(STDERR,"RED: $label expected ".var_export($expected,true)." got ".var_export($actual,true)."; writes=".BanState::$writes."\n");exit(1);}
    ++$assertions;
    if (BanState::$writes!==$writes || BanForum::$audit!==$audits) {
        fwrite(STDERR,"FAIL: $label writes=".BanState::$writes." audits=".BanForum::$audit." (expected $writes/$audits)\n");exit(1);
    }
    $assertions+=2;
    if($expected===403 && $targetExists && BanState::$people[2]['is_banned']!==$targetBanned){
        fwrite(STDERR,"FAIL: $label modified protected target\n");exit(1);
    }
    ++$assertions;
}
echo "PASS: $assertions real AdminController assertions with mutable fake DB (NOT PHP PDO/HTTP)\n";
