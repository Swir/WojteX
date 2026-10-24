<?php
/**
 * Real AuthController::profile using a deterministic stale-session / live-DB seam.
 * A ban committed after request authentication must stop the profile write.
 * No HTTP, PDO, CSRF, actual network or physical concurrency is claimed.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\AuthController;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ProfileLegacyStaleSave extends RuntimeException {}
class ProfileAuthorizedRefreshed extends RuntimeException {}
final class ProfileLiveDb {
    public bool $exists = true;
    public bool $banned = false;
    public array $row = [];
    public int $writes = 0;
    public int $attempts = 0;
    public int $checks = 0;
    public int $loads = 0;
    public function reset(bool $banned, bool $exists, bool $pref): void {
        $this->exists=$exists; $this->banned=$banned;
        $this->row=['id'=>7,'name'=>'Before','bio'=>'Original bio','allow_private_messages'=>$pref,'role'=>'member','is_banned'=>$banned];
        $this->writes=0;$this->attempts=0;$this->checks=0;$this->loads=0;
    }
    public function table(string $table): ProfileLiveQuery {
        if ($table !== 'users') throw new RuntimeException('Unexpected table');
        return new ProfileLiveQuery($this);
    }
}
final class ProfileLiveQuery {
    private array $where=[];
    public function __construct(private ProfileLiveDb $db) {}
    public function where(string $column, mixed $operation, mixed $value=null): self {
        if (!in_array($column,['id','is_banned'],true)) throw new RuntimeException('Unexpected profile WHERE '.$column);
        $this->where[$column]=$value===null?$operation:$value;
        return $this;
    }
    private function allowed(): bool {
        ++$this->db->checks;
        if (($this->where['id']??null)!==7 || ($this->where['is_banned']??null)!==false)
            throw new RuntimeException('Profile mutation missing live id/ban predicate');
        return $this->db->exists && !$this->db->banned;
    }
    public function update(array $values): int {
        ++$this->db->attempts;
        if (!$this->allowed()) return 0;
        if (!array_key_exists('name',$values) || !array_key_exists('bio',$values)
            || !array_key_exists('updated_at',$values) || array_key_exists('password',$values)
            || array_key_exists('role',$values) || array_key_exists('is_banned',$values))
            throw new RuntimeException('Profile UPDATE changed unexpected columns');
        $changed=false;
        foreach (['name','bio','allow_private_messages'] as $column) {
            if (array_key_exists($column,$values) && $this->db->row[$column]!==$values[$column]) $changed=true;
        }
        foreach ($values as $column=>$value) if ($column!=='updated_at') $this->db->row[$column]=$value;
        if ($changed) { ++$this->db->writes; return 1; }
        // Deliberately model MySQL: an authorized no-op may report zero rows.
        return 0;
    }
    public function first(array $columns=[]): ?object {
        ++$this->db->loads;
        if (!$this->allowed()) return null;
        return (object)array_intersect_key($this->db->row,array_flip($columns));
    }
}
final class ProfileRaceUser extends User {
    public int $saveCalls=0;
    public int $refreshCalls=0;
    public function save(array $options=[]): bool {
        ++$this->saveCalls;
        throw new ProfileLegacyStaleSave('Old unguarded Eloquent save reached');
    }
    public function refresh(): static {
        ++$this->refreshCalls;
        throw new ProfileAuthorizedRefreshed('Guarded write completed; refresh reached');
    }
}
final class ProfileRaceRequest extends Request {
    public static array $payload=[];
    public function validate(array $rules): array {return self::$payload;}
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
$db=new ProfileLiveDb();DB::swap($db);
$controller=new AuthController();$checks=0;
$cases=[
    ['banned after preflight',false,true,true,['name'=>'Renamed','bio'=>'Changed','allow_private_messages'=>false],403,0],
    ['deleted after preflight',false,false,false,['name'=>'Renamed','bio'=>'Changed','allow_private_messages'=>false],403,0],
    ['already banned',true,true,true,['name'=>'Renamed','bio'=>'Changed','allow_private_messages'=>false],403,0],
    ['active update',false,false,true,['name'=>'Renamed','bio'=>'Changed','allow_private_messages'=>false],'refreshed',1],
    ['preserve opt-out when omitted',false,false,true,['name'=>'Renamed','bio'=>'Changed'],'refreshed',1],
    ['active unchanged profile (MySQL zero affected)',false,false,true,['name'=>'Before','bio'=>'Original bio','allow_private_messages'=>true],'refreshed',0],
];
foreach ($cases as [$label,$cachedBan,$liveBan,$liveExists,$payload,$expected,$expectedWrites]) {
    $db->reset($liveBan,$liveExists,$label==='preserve opt-out when omitted'?false:true);
    ProfileRaceRequest::$payload=$payload;
    $account=new ProfileRaceUser();
    $account->forceFill(['id'=>7,'name'=>'Before','bio'=>'Original bio','role'=>'member','color'=>'violet','is_banned'=>$cachedBan,'allow_private_messages'=>true]);
    $request=ProfileRaceRequest::create('/api/profile','PATCH',$payload);
    $request->setUserResolver(static fn()=>$account);
    $actual='returned';
    try {$controller->profile($request);}
    catch(HttpExceptionInterface $e) {$actual=$e->getStatusCode();}
    catch(ProfileLegacyStaleSave) {$actual='old unguarded save';}
    catch(ProfileAuthorizedRefreshed) {$actual='refreshed';}
    if ($actual!==$expected) {fwrite(STDERR,"FAIL {$label}: expected {$expected}, got {$actual}, SQL attempts={$db->attempts}, writes={$db->writes}\n");exit(1);}
    ++$checks;
    if ($db->writes!==$expectedWrites || $account->saveCalls!==0) {fwrite(STDERR,"FAIL {$label}: illegal mutation or Eloquent stale save\n");exit(1);}
    $checks+=2;
    if (!$cachedBan && $db->attempts!==1) {fwrite(STDERR,"FAIL {$label}: missing SQL live-ban gate\n");exit(1);}
    ++$checks;
    if ($expected==='refreshed' && $account->refreshCalls!==1) {fwrite(STDERR,"FAIL {$label}: no fresh model reload\n");exit(1);}
    if ($expected===403 && $account->refreshCalls!==0) {fwrite(STDERR,"FAIL {$label}: rejected profile refreshed\n");exit(1);}
    ++$checks;
    if ($label==='preserve opt-out when omitted' && $db->row['allow_private_messages']!==false) {fwrite(STDERR,"FAIL: omitted preference overwritten\n");exit(1);}
    ++$checks;
    if ($label==='banned after preflight' && ($db->row['name']!=='Before'||$db->row['bio']!=='Original bio')) {fwrite(STDERR,"FAIL: banned account changed\n");exit(1);}
    ++$checks;
}
echo "PASS {$checks} real AuthController::profile checks with mutable fake database (NOT PDO/HTTP)\n";
