<?php
/**
 * Executes the real RequireAdmin middleware with a mutable database facade.
 * Exercises stale authenticated role, live ban/removal and transaction lock
 * across downstream admin reads/writes. Not HTTP, PDO, or native concurrency.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Middleware\RequireAdmin;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class AdminGuardState
{
    public static ?array $row = null;
    public static int $transactions = 0;
    public static int $locks = 0;
    public static int $commits = 0;
    public static int $rollbacks = 0;
    public static int $reads = 0;
    public static bool $inside = false;
    public static bool $rowLocked = false;
    public static int $downstream = 0;
    public static bool $downstreamHadLock = false;
    public static function reset(?array $row): void
    {
        self::$row = $row;
        self::$transactions = self::$locks = self::$commits = self::$rollbacks = self::$reads = self::$downstream = 0;
        self::$inside = self::$rowLocked = self::$downstreamHadLock = false;
    }
}
final class AdminGuardDB
{
    public function table(string $table): AdminGuardQuery
    {
        if ($table !== 'users') throw new RuntimeException('Unexpected table '.$table);
        return new AdminGuardQuery();
    }
    public function transaction(callable $callback): mixed
    {
        if (AdminGuardState::$inside) throw new RuntimeException('Unexpected nested transaction');
        ++AdminGuardState::$transactions;
        AdminGuardState::$inside = true;
        try {
            $result = $callback();
            ++AdminGuardState::$commits;
            return $result;
        } catch (Throwable $e) {
            ++AdminGuardState::$rollbacks;
            throw $e;
        } finally {
            AdminGuardState::$inside = AdminGuardState::$rowLocked = false;
        }
    }
}
final class AdminGuardQuery
{
    private int $id = -1;
    public function where(string $column, mixed $id): self
    {
        if ($column !== 'id') throw new RuntimeException('Unexpected filter');
        $this->id = (int)$id;
        return $this;
    }
    public function lockForUpdate(): self
    {
        if (!AdminGuardState::$inside) throw new RuntimeException('Lock outside transaction');
        ++AdminGuardState::$locks;
        AdminGuardState::$rowLocked = true;
        return $this;
    }
    public function first(array $columns = ['*']): ?object
    {
        if ($columns !== ['role', 'is_banned']) throw new RuntimeException('Only role and ban needed');
        if (!AdminGuardState::$rowLocked) throw new RuntimeException('Unlocked authorization read');
        ++AdminGuardState::$reads;
        $row = AdminGuardState::$row;
        return $row && (int)$row['id'] === $this->id ? (object)$row : null;
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
DB::swap(new AdminGuardDB());
$guard = new RequireAdmin();
$checks = 0;
function guardAssert(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) {
        fwrite(STDERR,'RED: '.$message.' downstream='.AdminGuardState::$downstream
            .' locks='.AdminGuardState::$locks.' transactions='.AdminGuardState::$transactions."\n");
        exit(1);
    }
    ++$checks;
}
function runAdminCase(RequireAdmin $guard, ?object $session, string $method = 'GET', bool $throws = false): mixed
{
    $request = Request::create('/api/admin/users/search', $method);
    $request->setUserResolver(static fn () => $session);
    $next = static function () use ($throws): string {
        ++AdminGuardState::$downstream;
        AdminGuardState::$downstreamHadLock = AdminGuardState::$inside && AdminGuardState::$rowLocked;
        if ($throws) throw new RuntimeException('downstream failure');
        return 'authorized';
    };
    try { return $guard->handle($request, $next); }
    catch (HttpExceptionInterface $e) { return $e->getStatusCode(); }
    catch (RuntimeException $e) { return 'exception: '.$e->getMessage(); }
}
$cachedAdmin = (object)['id'=>7,'role'=>'admin','is_banned'=>false];
foreach (['demoted'=>['id'=>7,'role'=>'member','is_banned'=>false],
          'banned'=>['id'=>7,'role'=>'admin','is_banned'=>true],
          'deleted'=>null,
          'wrong_id'=>['id'=>12,'role'=>'admin','is_banned'=>false]] as $case=>$row) {
    AdminGuardState::reset($row);
    $result = runAdminCase($guard,$cachedAdmin);
    guardAssert($result === 403, "$case stale cached admin must get 403, got ".var_export($result,true));
    guardAssert(AdminGuardState::$downstream === 0, "$case cannot enter admin controller");
    guardAssert(AdminGuardState::$transactions === 1 && AdminGuardState::$locks === 1,
        "$case live row must be locked before denial");
    guardAssert(AdminGuardState::$rollbacks === 1 && AdminGuardState::$commits === 0,
        "$case refusal rolls back database transaction");
}
// Even a session that has not refreshed a newly granted role must use live DB truth.
AdminGuardState::reset(['id'=>7,'role'=>'admin','is_banned'=>false]);
$promoted = (object)['id'=>7,'role'=>'member','is_banned'=>false];
$result = runAdminCase($guard,$promoted);
guardAssert($result === 'authorized','live promotion must allow current admin');
guardAssert(AdminGuardState::$downstream === 1 && AdminGuardState::$commits === 1,
    'successful admin controller executes within committed transaction');
guardAssert(AdminGuardState::$downstreamHadLock, 'transaction lock spans admin controller execution');
guardAssert(AdminGuardState::$locks === 1 && AdminGuardState::$reads === 1,
    'only one locked actor read');
guardAssert(!AdminGuardState::$inside && !AdminGuardState::$rowLocked,
    'row lock released after response');
AdminGuardState::reset(['id'=>7,'role'=>'admin','is_banned'=>false]);
$result = runAdminCase($guard,$cachedAdmin,'PATCH');
guardAssert($result === 'authorized','authorized admin write succeeds');
guardAssert(AdminGuardState::$commits === 1 && AdminGuardState::$locks === 1,
    'admin write is protected up to transaction commit');
AdminGuardState::reset(['id'=>7,'role'=>'admin','is_banned'=>false]);
$result = runAdminCase($guard,$cachedAdmin,'POST',true);
guardAssert($result === 'exception: downstream failure','downstream failures are propagated');
guardAssert(AdminGuardState::$rollbacks === 1 && AdminGuardState::$commits === 0,
    'downstream failure rolls back guarded transaction');
AdminGuardState::reset(['id'=>7,'role'=>'admin','is_banned'=>false]);
$result = runAdminCase($guard,null);
guardAssert($result === 403 && AdminGuardState::$downstream === 0,
    'guest must never reach admin controller');
guardAssert(AdminGuardState::$locks === 0 && AdminGuardState::$transactions === 0,
    'guest is rejected without unnecessary DB lock');
echo "PASS: $checks real RequireAdmin middleware checks with fake DB (not PHP PDO/HTTP/concurrency)\n";
