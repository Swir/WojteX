<?php
/**
 * Executes production RequireFreshForumViewer against mutable fake DB.
 * Tests stale staff role / ban / removal and lock spans the downstream read.
 * No PHP PDO, HTTP, or real interleaving is exercised.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Middleware\RequireFreshForumViewer;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ForumReadFakeState
{
    public static ?array $live = null;
    public static int $transactions = 0;
    public static int $locks = 0;
    public static int $commits = 0;
    public static int $rollbacks = 0;
    public static int $reads = 0;
    public static int $downstream = 0;
    public static bool $inTransaction = false;
    public static bool $locked = false;
    public static bool $nextLocked = false;
    public static ?string $nextRole = null;
    public static function reset(?array $live): void
    {
        self::$live = $live;
        self::$transactions = self::$locks = self::$commits = self::$rollbacks = self::$reads = 0;
        self::$downstream = 0;
        self::$inTransaction = self::$locked = self::$nextLocked = false;
        self::$nextRole = null;
    }
}
final class ForumReadFakeDB
{
    public function table(string $table): ForumReadFakeQuery
    {
        if ($table !== 'users') throw new RuntimeException('Unexpected table');
        return new ForumReadFakeQuery();
    }
    public function transaction(callable $run): mixed
    {
        ++ForumReadFakeState::$transactions;
        if (ForumReadFakeState::$inTransaction) throw new RuntimeException('unexpected nested transaction');
        ForumReadFakeState::$inTransaction = true;
        try {
            $result = $run();
            ++ForumReadFakeState::$commits;
            return $result;
        } catch (Throwable $e) {
            ++ForumReadFakeState::$rollbacks;
            throw $e;
        } finally {
            ForumReadFakeState::$inTransaction = ForumReadFakeState::$locked = false;
        }
    }
}
final class ForumReadFakeQuery
{
    private int $id = 0;
    public function where(string $key, mixed $id): self
    {
        if ($key !== 'id') throw new RuntimeException('Unexpected where');
        $this->id = (int) $id;
        return $this;
    }
    public function lockForUpdate(): self
    {
        if (!ForumReadFakeState::$inTransaction) throw new RuntimeException('Lock outside transaction');
        ForumReadFakeState::$locked = true;
        ++ForumReadFakeState::$locks;
        return $this;
    }
    public function first(array $columns): ?object
    {
        if ($columns !== ['id','role','is_banned'] || !ForumReadFakeState::$locked)
            throw new RuntimeException('Unexpected or unlocked identity read');
        ++ForumReadFakeState::$reads;
        $row = ForumReadFakeState::$live;
        return $row && (int) $row['id'] === $this->id ? (object) $row : null;
    }
}
$app = new class extends Container {
    public function abort(int $code, string $message = '', array $headers = []): never
    {
        throw new Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers);
    }
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('translator',new class { public function get(string $key): string { return $key; } });
DB::swap(new ForumReadFakeDB());
$guard = new RequireFreshForumViewer();
$checks = 0;
function readAssert(bool $test, string $message): void
{
    global $checks;
    if (!$test) {
        fwrite(STDERR, 'RED: '.$message.' live='.json_encode(ForumReadFakeState::$live)
            .' next='.ForumReadFakeState::$downstream.' nextRole='.ForumReadFakeState::$nextRole."\n");
        exit(1);
    }
    ++$checks;
}
function runReadCase(RequireFreshForumViewer $guard, ?object $cached, bool $error = false): mixed
{
    $request = Request::create('/api/threads/11', 'GET');
    $request->setUserResolver(static fn () => $cached);
    $next = static function (Request $request) use ($error): string {
        ++ForumReadFakeState::$downstream;
        ForumReadFakeState::$nextLocked = ForumReadFakeState::$locked && ForumReadFakeState::$inTransaction;
        ForumReadFakeState::$nextRole = $request->user()?->role;
        if ($error) throw new RuntimeException('downstream failed');
        return $request->user()?->role === 'admin' || $request->user()?->role === 'moderator'
            ? 'private-content' : 'public-only';
    };
    try { return $guard->handle($request, $next); }
    catch (HttpExceptionInterface $e) { return $e->getStatusCode(); }
    catch (RuntimeException $e) { return 'exception: '.$e->getMessage(); }
}
$cachedStaff = (object) ['id'=>11, 'role'=>'admin', 'is_banned'=>false];
foreach (['demoted'=>['id'=>11,'role'=>'member','is_banned'=>false],
          'demoted moderator'=>['id'=>11,'role'=>'member','is_banned'=>false]] as $kind=>$state) {
    $cached = clone $cachedStaff;
    if ($kind === 'demoted moderator') $cached->role = 'moderator';
    ForumReadFakeState::reset($state);
    $result=runReadCase($guard,$cached);
    readAssert($result==='public-only', "$kind must NOT see private thread despite cached staff");
    readAssert($cached->role==='member', "$kind must update downstream role from live row");
    readAssert(ForumReadFakeState::$nextLocked, "$kind live row lock spans response construction");
    readAssert(ForumReadFakeState::$transactions===1 && ForumReadFakeState::$locks===1 && ForumReadFakeState::$reads===1, "$kind single locked live lookup");
    readAssert(ForumReadFakeState::$commits===1 && ForumReadFakeState::$rollbacks===0, "$kind success commits");
}
foreach (['banned'=>['id'=>11,'role'=>'admin','is_banned'=>true],
          'removed'=>null,
          'wrong account'=>['id'=>12,'role'=>'admin','is_banned'=>false]] as $kind=>$state) {
    ForumReadFakeState::reset($state);
    $result=runReadCase($guard,clone $cachedStaff);
    readAssert($result===403, "$kind must be forbidden");
    readAssert(ForumReadFakeState::$downstream===0, "$kind cannot reach private content");
    readAssert(ForumReadFakeState::$rollbacks===1 && ForumReadFakeState::$commits===0, "$kind abort rolls back transaction");
    readAssert(ForumReadFakeState::$locks===1, "$kind live row read was locked");
}
ForumReadFakeState::reset(['id'=>11,'role'=>'admin','is_banned'=>false]);
readAssert(runReadCase($guard,clone $cachedStaff)==='private-content', 'current administrator retains access');
readAssert(ForumReadFakeState::$nextLocked && ForumReadFakeState::$commits===1, 'current staff access occurs under lock');
ForumReadFakeState::reset(['id'=>11,'role'=>'moderator','is_banned'=>false]);
$cachedMember = (object) ['id'=>11, 'role'=>'member', 'is_banned'=>false];
readAssert(runReadCase($guard,$cachedMember)==='private-content', 'live promotion used rather than stale member role');
readAssert($cachedMember->role==='moderator', 'current moderator role is propagated to downstream');
ForumReadFakeState::reset(['id'=>11,'role'=>'member','is_banned'=>false]);
readAssert(runReadCase($guard,clone $cachedStaff,true)==='exception: downstream failed', 'downstream exception must propagate');
readAssert(ForumReadFakeState::$rollbacks===1 && ForumReadFakeState::$commits===0, 'downstream exception rolls back identity lock');
ForumReadFakeState::reset(null);
readAssert(runReadCase($guard,null)==='public-only', 'guest sees only public forum');
readAssert(ForumReadFakeState::$transactions===0 && ForumReadFakeState::$locks===0, 'guest uses no user lock');
ForumReadFakeState::reset(null);
$invalid=(object)['id'=>0,'role'=>'admin','is_banned'=>false];
readAssert(runReadCase($guard,$invalid)===403, 'invalid authenticated identity is denied');
readAssert(ForumReadFakeState::$transactions===0, 'invalid identity is denied before DB transaction');
echo "PASS: $checks checks of real RequireFreshForumViewer with mutable fake DB (not PHP PDO/HTTP/concurrency)\n";
