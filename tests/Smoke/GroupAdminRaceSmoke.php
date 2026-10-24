<?php
/**
 * Real GroupController with mutable DB facade doubles. RED on dev.32:
 * demoted/banned group administrator could update private category grants
 * after middleware/preflight. No PHP PDO/HTTP or real isolation proof.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\GroupController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class GroupAdminReply extends RuntimeException {}
final class GroupAdminState
{
    public static ?array $actor;
    public static int $writes = 0;
    public static int $audits = 0;
    public static int $locks = 0;
    public static int $transactions = 0;
    public static $beforeGroupRead = null;
    public static function reset(): void
    {
        self::$actor = ['id'=>1, 'role'=>'admin','is_banned'=>false];
        self::$writes = self::$audits = self::$locks = self::$transactions = 0;
        self::$beforeGroupRead = null;
    }
}
final class GroupAdminForumDouble
{
    public static function audit(string $event, array $details): void
    {
        if (!in_array($event, ['group.permissions.updated','group.renamed','group.deleted'], true)) {
            throw new RuntimeException('Unexpected audit '.$event);
        }
        ++GroupAdminState::$audits;
    }
}
class_alias(GroupAdminForumDouble::class,'App\\Services\\ForumService');
final class GroupAdminDB
{
    public function table(string $table): GroupAdminQuery {return new GroupAdminQuery($table);}
    public function transaction(callable $callback): mixed
    {
        ++GroupAdminState::$transactions;
        return $callback();
    }
}
final class GroupAdminQuery
{
    private array $conditions = [];
    public function __construct(private string $table) {}
    public function where(string $column, mixed $operator, mixed $value = null): self
    {
        $this->conditions[$column] = $value === null ? $operator : $value;
        return $this;
    }
    public function lockForUpdate(): self
    {
        if ($this->table !== 'users') throw new RuntimeException('Unexpected row lock');
        ++GroupAdminState::$locks;
        return $this;
    }
    public function first(array $columns = ['*']): ?object
    {
        if ($this->table !== 'users') throw new RuntimeException('Unexpected first '.$this->table);
        $person = GroupAdminState::$actor;
        return $person && (int)$person['id'] === (int)($this->conditions['id'] ?? -1)
            ? (object)$person : null;
    }
    public function find(int $id): ?object
    {
        if ($this->table !== 'forum_groups') throw new RuntimeException('Unexpected find');
        if (GroupAdminState::$beforeGroupRead) {
            $callback = GroupAdminState::$beforeGroupRead;
            GroupAdminState::$beforeGroupRead = null;
            $callback();
        }
        return $id === 7 ? (object)['id'=>7,'name'=>'Readers'] : null;
    }
    public function exists(): bool
    {
        if ($this->table === 'categories') return ($this->conditions['id'] ?? null) === 12;
        if ($this->table === 'forum_groups') return false; // fresh slug
        throw new RuntimeException('Unexpected exists '.$this->table);
    }
    public function updateOrInsert(array $key, array $values): bool
    {
        if ($this->table !== 'category_group_permissions' || $key !== ['group_id'=>7,'category_id'=>12]
            || $values['allow_view'] !== true) throw new RuntimeException('Bad private grant');
        ++GroupAdminState::$writes;
        return true;
    }
    public function update(array $data): int
    {
        if ($this->table !== 'forum_groups') throw new RuntimeException('Unexpected update');
        ++GroupAdminState::$writes; return 1;
    }
    public function delete(): int
    {
        if (!in_array($this->table, ['forum_groups','category_group_permissions'], true)) {
            throw new RuntimeException('Unexpected delete');
        }
        ++GroupAdminState::$writes; return 1;
    }
}
final class GroupAdminRequest extends Request
{
    public static array $permission = ['view'=>true,'create'=>true,'reply'=>true];
    public function validate(array $rules): array
    {
        if (isset($rules['view'])) return self::$permission;
        if (isset($rules['name'])) return ['name'=>'Readers updated'];
        throw new RuntimeException('Unexpected validation');
    }
}
$app = new class extends Container {
    public function abort(int $code, string $message='', array $headers=[]): never
    {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('translator',new class {
    public function get(string $key): string {return $key;}
});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json($data=[], $status=200, array $headers=[], $options=0) {
            throw new GroupAdminReply('ok');
        }
    });
DB::swap(new GroupAdminDB());
$controller = new GroupController();
$checks=0;
function verifyGroupAdmin(bool $pass, string $label): void
{
    global $checks;
    if (!$pass) {
        fwrite(STDERR, 'RED: '.$label.' writes='.GroupAdminState::$writes
            .' audits='.GroupAdminState::$audits.' locks='.GroupAdminState::$locks."\n");
        exit(1);
    }
    ++$checks;
}
function invokeGroupAdmin(GroupController $controller, string $method): string|int
{
    $request = GroupAdminRequest::create('/api/admin/groups/7','PATCH');
    $request->setUserResolver(static fn () => (object)['id'=>1,'role'=>'admin','is_banned'=>false]);
    try {
        if ($method === 'permission') $controller->permission($request,7,12);
        elseif ($method === 'rename') $controller->rename($request,7);
        elseif ($method === 'delete') $controller->delete($request,7);
        else throw new RuntimeException('Invalid method');
        return 'returned';
    } catch (GroupAdminReply $e) {return 'ok';}
      catch (HttpExceptionInterface $e) {return $e->getStatusCode();}
}
foreach (['permission','rename','delete'] as $method) {
    foreach (['demoted','banned','deleted'] as $change) {
        GroupAdminState::reset();
        GroupAdminState::$beforeGroupRead=static function () use ($change) {
            if ($change==='demoted') GroupAdminState::$actor['role']='member';
            if ($change==='banned') GroupAdminState::$actor['is_banned']=true;
            if ($change==='deleted') GroupAdminState::$actor=null;
        };
        $status=invokeGroupAdmin($controller,$method);
        verifyGroupAdmin($status===403,"$method $change expected 403 got ".var_export($status,true));
        verifyGroupAdmin(GroupAdminState::$writes===0,"$method $change no mutation");
        verifyGroupAdmin(GroupAdminState::$audits===0,"$method $change no audit");
    }
    GroupAdminState::reset();
    $status=invokeGroupAdmin($controller,$method);
    verifyGroupAdmin($status==='ok',"$method current active admin succeeds");
    verifyGroupAdmin(GroupAdminState::$writes===1,"$method exactly one mutation");
    verifyGroupAdmin(GroupAdminState::$audits===1,"$method exactly one audit");
    verifyGroupAdmin(GroupAdminState::$transactions===1,"$method mutation in one transaction");
    verifyGroupAdmin(GroupAdminState::$locks===1,"$method current actor row locked");
}
echo "PASS: $checks real GroupController security assertions with mutable fake DB (NOT PHP PDO/HTTP)\n";
