<?php
/**
 * Actual AuthController::profile with mocked query boundary. A cached-banned
 * account must stop before any write; active accounts must use the live SQL
 * ban guard. No HTTP, PDO or concurrent DB transaction is simulated here.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\AuthController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class GuardedProfileUpdateReached extends RuntimeException {}
final class BannedProfileSmokeAccount
{
    public int $id = 7;
    public string $name = 'Initial';
    public string $bio = 'Previous';
    public bool $allow_private_messages = true;
    public function __construct(public bool $is_banned) {}
    public function save(): never {throw new RuntimeException('Unguarded Eloquent save forbidden');}
}
final class BannedProfileSmokeQuery
{
    private array $wheres=[];
    public function where(string $column, mixed $value): self {$this->wheres[$column]=$value;return $this;}
    public function update(array $values): never
    {
        if (($this->wheres['id']??null)!==7 || ($this->wheres['is_banned']??null)!==false
            || ($values['name']??null)!=='Renamed while banned' || ($values['bio']??null)!=='Changed profile'
            || ($values['allow_private_messages']??null)!==false || !isset($values['updated_at'])) {
            throw new RuntimeException('Profile query lost live account ban, identity or data');
        }
        throw new GuardedProfileUpdateReached('Guarded query was reached');
    }
}
final class BannedProfileSmokeDB
{
    public int $tables = 0;
    public function table(string $table): BannedProfileSmokeQuery
    {
        if ($table!=='users') throw new RuntimeException('Unexpected table');
        ++$this->tables;
        return new BannedProfileSmokeQuery();
    }
}
final class BannedProfileSmokeRequest extends Request
{
    public function validate(array $rules): array
    {
        return ['name'=>'Renamed while banned','bio'=>'Changed profile','allow_private_messages'=>false];
    }
}
$app = new class extends Container {
    public function abort(int $code, string $message = '', array $headers=[]): never {
        throw new HttpException($code, $message, null, $headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$translator = new class {
    public string $locale='en';
    public function get(string $key): string {
        $messages=json_decode(file_get_contents(dirname(__DIR__, 2).'/lang/'.$this->locale.'/server.json'),true,64,JSON_THROW_ON_ERROR);
        return $messages[$key]??throw new RuntimeException('Missing locale '.$this->locale.': '.$key);
    }
};
$app->instance('translator',$translator);
$db=new BannedProfileSmokeDB();DB::swap($db);
$controller=new AuthController();$checks=0;
foreach (['pl','en'] as $locale) {
    $translator->locale=$locale;
    foreach ([true,false] as $banned) {
        $db->tables=0;
        $account=new BannedProfileSmokeAccount($banned);
        $request=BannedProfileSmokeRequest::create('/api/profile','PATCH',['name'=>'Renamed while banned']);
        $request->setUserResolver(static fn()=>$account);
        $actual='unexpected';$reason=null;
        try {$controller->profile($request);}
        catch(HttpExceptionInterface $e) {$actual=$e->getStatusCode();$reason=$e->getMessage();}
        catch(GuardedProfileUpdateReached) {$actual='guarded';}
        $expected=$banned?403:'guarded';
        if ($actual!==$expected || $db->tables!==($banned?0:1)) {
            fwrite(STDERR,"FAIL {$locale}, banned=".($banned?'1':'0').": expected {$expected}, actual ".json_encode($actual).", queries={$db->tables}\n");exit(1);
        }
        $checks+=2;
        if ($banned && $reason!==$translator->get('server.account_blocked')) {
            fwrite(STDERR,"FAIL {$locale}: missing translated 403\n");exit(1);
        }
        ++$checks;
        if ($account->name!=='Initial'||$account->bio!=='Previous'||!$account->allow_private_messages) {
            fwrite(STDERR,"FAIL {$locale}: stale model modified instead of guarded SQL\n");exit(1);
        }
        ++$checks;
    }
}
echo "PASS {$checks} checks cached ban and guarded profile SQL entry (fake DB, NOT HTTP/PDO)\n";
