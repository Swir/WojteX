<?php
/**
 * Exercise actual CommunityController::shout/deleteShout with a fake database
 * whose persisted plugin state changes AFTER ExtensionRegistry's preflight.
 * This is a deterministic controller seam, NOT HTTP/PDO/true concurrency.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\CommunityController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ShoutLivePluginMutation extends RuntimeException {}
class ShoutLiveForum {
    public static function isModerator(?object $user): bool {
        return in_array($user?->role, ['admin', 'moderator'], true);
    }
}
class_alias(ShoutLiveForum::class, 'App\\Services\\ForumService');
class ShoutLiveDatabase {
    public bool $preflightEnabled = true;
    public bool $currentEnabled = true;
    public bool $accountExists = true;
    public bool $accountBanned = false;
    public string $currentRole = 'member';
    public int $actorId=7;
    public int $ownerId=7;
    public int $preflightReads=0;
    public int $mutations=0;
    public int $dmlAttempts=0;
    public function table(string $table): ShoutLiveQuery {
        return new ShoutLiveQuery($this, $table);
    }
}
class ShoutLiveQuery {
    public array $predicates=[];
    public bool $pluginGate=false;
    public bool $userGate=false;
    public bool $staffGate=false;
    private string $source='';
    private bool $hasSelection=false;
    public function __construct(private ShoutLiveDatabase $db, public string $table) {}
    public function where(mixed $column, mixed $operator=null, mixed $value=null): self {
        if (is_callable($column)) {
            $column($this);
            return $this;
        }
        $this->predicates[$column] = $value ?? $operator;
        return $this;
    }
    public function from(string $source): self {$this->source=$source; return $this;}
    public function selectRaw(string $sql, array $bindings=[]): self {
        $this->hasSelection=true;
        return $this;
    }
    public function whereIn(string $column, array $values): self {
        if($column==='current_staff.role' && in_array('admin',$values,true) && in_array('moderator',$values,true)) $this->staffGate=true;
        return $this;
    }
    public function orWhereExists(callable $callback): self {
        $query=new self($this->db,'subquery'); $callback($query);
        if ($query->source==='users as current_staff' && $query->staffGate) $this->staffGate=true;
        return $this;
    }
    public function whereExists(callable $callback): self {
        $query=new self($this->db,'subquery'); $callback($query);
        if ($query->source==='plugin_states as live_shoutbox'
            && ($query->predicates['live_shoutbox.key'] ?? null)==='shoutbox'
            && ($query->predicates['live_shoutbox.enabled'] ?? null)===true) {
            $this->pluginGate=true;
        }
        if ($query->source==='users as current_actor'
            && ($query->predicates['current_actor.is_banned'] ?? null)===false) $this->userGate=true;
        return $this;
    }
    public function value(string $column): bool {
        if ($this->table!=='plugin_states' || $column!=='enabled') throw new RuntimeException('Unexpected value query');
        ++$this->db->preflightReads;
        return $this->db->preflightReads===1 ? $this->db->preflightEnabled : $this->db->currentEnabled;
    }
    public function find(int $id): object {
        if ($this->table!=='shouts' || $id!==25) throw new RuntimeException('Unexpected find');
        return (object)['id'=>25,'user_id'=>7];
    }
    public function insertUsing(array $columns, mixed $select): int {
        if ($this->table!=='shouts' || $columns!==['user_id','body','created_at','updated_at']
            || !$select instanceof self || $select->table!=='users' || !$select->hasSelection
            || ($select->predicates['users.id'] ?? null)!==7
            || ($select->predicates['users.is_banned'] ?? null)!==false) {
            throw new RuntimeException('Existing account protection missing');
        }
        ++$this->db->dmlAttempts;
        if (!$this->db->accountExists || $this->db->accountBanned
            || ($select->pluginGate && !$this->db->currentEnabled)) return 0;
        ++$this->db->mutations;
        throw new ShoutLivePluginMutation('Real controller reached INSERT');
    }
    public function delete(): int {
        if ($this->table!=='shouts' || !$this->userGate) throw new RuntimeException('Existing owner protection missing');
        ++$this->db->dmlAttempts;
        if (!$this->db->accountExists || $this->db->accountBanned
            || ($this->pluginGate && !$this->db->currentEnabled)) return 0;
        if ($this->db->actorId!==$this->db->ownerId
            && !($this->staffGate && in_array($this->db->currentRole, ['moderator', 'admin'], true))) return 0;
        ++$this->db->mutations;
        throw new ShoutLivePluginMutation('Real controller reached DELETE');
    }
}
class ShoutLiveRequest extends Request {
    public function validate(array $rules): array {return ['body'=>'A safe local test shout'];}
}
$app = new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]): never {
        throw new HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {
    public function get(string $key): string {return $key;}
});
$db=new ShoutLiveDatabase(); DB::swap($db);
$controller=new CommunityController(); $checks=0;
$cases=[
    ['post','disabled during preflight gap',true,false,false,'member','member',404,0],
    ['delete','disabled during preflight gap',true,false,false,'member','member',404,0],
    ['post','enabled active poster',true,true,false,'member','member','write',1],
    ['delete','enabled owner',true,true,false,'member','member','write',1],
    ['post','disabled before request',false,false,false,'member','member',404,0],
    ['delete','disabled before request',false,false,false,'member','member',404,0],
    ['post','banned after preflight',true,true,true,'member','member',403,0],
    ['delete','banned after preflight',true,true,true,'member','member',403,0],
    ['delete','current moderator permitted',true,true,false,'moderator','moderator','write',1],
    ['delete','demoted moderator cannot delete',true,true,false,'moderator','member',403,0],
];
foreach($cases as [$method,$label,$initial,$current,$banned,$cachedRole,$currentRole,$expected,$writes]) {
    $db->preflightEnabled=$initial;$db->currentEnabled=$current;$db->accountBanned=$banned;
    $db->accountExists=true;$db->currentRole=$currentRole;
    $actorId=($cachedRole==='moderator' ? 8 : 7);$db->actorId=$actorId;
    $db->preflightReads=$db->mutations=$db->dmlAttempts=0;
    $request=ShoutLiveRequest::create('/api/shouts'.($method==='delete'?'/25':''),$method==='delete'?'DELETE':'POST');
    $request->setUserResolver(static fn()=> (object)['id'=>$actorId,'role'=>$cachedRole,'is_banned'=>false]);
    try {
        if($method==='post')$controller->shout($request);
        else $controller->deleteShout($request,25);
        $actual='returned';
    } catch(ShoutLivePluginMutation) {$actual='write';}
    catch(HttpExceptionInterface $exception) {$actual=$exception->getStatusCode();}
    if($actual!==$expected || $db->mutations!==$writes) {
        fwrite(STDERR,"RED: $method $label expected status=$expected writes=$writes got status=$actual writes=$db->mutations attempts=$db->dmlAttempts\n");exit(1);
    }
    $checks+=2;
    if(!$initial && $db->dmlAttempts!==0) throw new RuntimeException('Disabled preflight attempted SQL');
    ++$checks;
}
echo "PASS: $checks actual CommunityController checks with fake DB (NOT PHP PDO/HTTP)\n";
