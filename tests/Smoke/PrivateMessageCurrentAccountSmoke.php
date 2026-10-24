<?php
/**
 * Real PM middleware with mutable database state and a synthetic downstream
 * private-data handler. Neither an HTTP request nor PHP PDO/concurrency test.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Middleware\RequireActivePrivateMessaging;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PMCurrentState {
    public static ?array $record=null;
    public static int $transactions=0,$commits=0,$rollbacks=0,$locks=0,$reads=0,$downstream=0;
    public static bool $inTransaction=false,$locked=false,$downstreamLocked=false;
    public static function reset(?array $record): void {
        self::$record=$record;
        self::$transactions=self::$commits=self::$rollbacks=self::$locks=self::$reads=self::$downstream=0;
        self::$inTransaction=self::$locked=self::$downstreamLocked=false;
    }
}
final class PMCurrentDB {
    public function table(string $table): PMCurrentQuery {
        if ($table!=='users') throw new RuntimeException('Unexpected table '.$table);
        return new PMCurrentQuery();
    }
    public function transaction(callable $callback): mixed {
        if (PMCurrentState::$inTransaction) throw new RuntimeException('Nested fake transaction');
        PMCurrentState::$inTransaction=true;
        ++PMCurrentState::$transactions;
        try {
            $result=$callback();
            ++PMCurrentState::$commits;
            return $result;
        } catch (Throwable $exception) {
            ++PMCurrentState::$rollbacks;
            throw $exception;
        } finally { PMCurrentState::$inTransaction=PMCurrentState::$locked=false; }
    }
}
final class PMCurrentQuery {
    private int $id=0;
    public function where(string $field,mixed $value): self {
        if ($field!=='id') throw new RuntimeException('Unexpected PM actor filter');
        $this->id=(int)$value;
        return $this;
    }
    public function lockForUpdate(): self {
        if (!PMCurrentState::$inTransaction) throw new RuntimeException('PM row lock outside transaction');
        PMCurrentState::$locked=true;
        ++PMCurrentState::$locks;
        return $this;
    }
    public function first(array $columns=['*']): ?object {
        if ($columns!==['id','is_banned'] || !PMCurrentState::$locked)
            throw new RuntimeException('Missing locked fresh PM authorization');
        ++PMCurrentState::$reads;
        return PMCurrentState::$record && PMCurrentState::$record['id']===$this->id
            ? (object)PMCurrentState::$record : null;
    }
}
$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]): never {
        throw new HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string {return $key;}});
DB::swap(new PMCurrentDB());
$guard=new RequireActivePrivateMessaging();
$checks=0;
function insist(bool $ok,string $reason): void {
    global $checks;
    if (!$ok) {fwrite(STDERR,'RED: '.$reason."\n");exit(1);} ++$checks;
}
function handlePm(RequireActivePrivateMessaging $guard,?object $session,bool $fail=false): mixed {
    $request=Request::create('/api/messages','GET');
    $request->setUserResolver(static fn()=>$session);
    try {
        return $guard->handle($request,static function() use($fail): string {
            ++PMCurrentState::$downstream;
            PMCurrentState::$downstreamLocked=PMCurrentState::$locked && PMCurrentState::$inTransaction;
            if ($fail) throw new RuntimeException('downstream failed');
            return 'synthetic-private-payload';
        });
    } catch(HttpExceptionInterface $e) {return $e->getStatusCode();}
    catch(RuntimeException $e) {return 'exception: '.$e->getMessage();}
}
$stale=(object)['id'=>17,'role'=>'member','is_banned'=>false];
foreach (['banned'=>['id'=>17,'is_banned'=>true], 'deleted'=>null, 'wrong user'=>['id'=>20,'is_banned'=>false]] as $label=>$record) {
    PMCurrentState::reset($record);
    insist(handlePm($guard,$stale)===403, "$label stale session must be refused");
    insist(PMCurrentState::$downstream===0, "$label must never see mailbox");
    insist(PMCurrentState::$transactions===1 && PMCurrentState::$locks===1 && PMCurrentState::$reads===1,
        "$label must use a real-time locked query");
    insist(PMCurrentState::$rollbacks===1 && PMCurrentState::$commits===0, "$label refusal must rollback");
}
PMCurrentState::reset(['id'=>17,'is_banned'=>false]);
insist(handlePm($guard,$stale)==='synthetic-private-payload','active account must reach PM handler');
insist(PMCurrentState::$downstream===1 && PMCurrentState::$downstreamLocked,
    'lock must span private response construction');
insist(PMCurrentState::$commits===1 && PMCurrentState::$rollbacks===0,'successful read must commit');
insist(!PMCurrentState::$inTransaction && !PMCurrentState::$locked,'no lingering transaction/lock');
PMCurrentState::reset(['id'=>17,'is_banned'=>false]);
insist(handlePm($guard,$stale,true)==='exception: downstream failed','exceptions propagate');
insist(PMCurrentState::$rollbacks===1 && PMCurrentState::$commits===0,'failing handler rolls back');
PMCurrentState::reset(['id'=>17,'is_banned'=>false]);
insist(handlePm($guard,null)===403,'guest must not pass PM guard');
insist(PMCurrentState::$transactions===0 && PMCurrentState::$locks===0,'guest requires no DB lock');
// Even a cached banned model must defer to the actual unbanned row.
PMCurrentState::reset(['id'=>17,'is_banned'=>false]);
insist(handlePm($guard,(object)['id'=>17,'is_banned'=>true])==='synthetic-private-payload',
    'live DB truth rather than stale session flag');
echo "PASS: $checks actual PM middleware fake-DB checks (NOT PDO, HTTP or native concurrency)\n";
