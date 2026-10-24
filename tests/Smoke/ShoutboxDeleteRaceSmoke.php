<?php
/**
 * Exercise the REAL CommunityController::deleteShout with a DB seam whose
 * durable ban / staff role differs from the earlier authenticated snapshot.
 * No PDO/HTTP is used. Separate Python SQLite test executes Laravel SQL.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\CommunityController;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class DeleteRaceReached extends RuntimeException {}
final class DeleteRaceDB {
    public bool $databaseBanned = false;
    public bool $databaseExists = true;
    public string $databaseRole = 'member';
    public int $actorId = 1;
    public int $ownerId = 1;
    public int $deletes = 0;
    public function table(string $table): DeleteRaceQuery {return new DeleteRaceQuery($this, $table);}
}
final class DeleteRaceQuery {
    public bool $guarded = false;
    public bool $staffGuarded = false;
    private string $fromTable = '';
    private bool $roleFilter = false;
    public function __construct(private DeleteRaceDB $database, private string $table) {}
    public function where(mixed $column, mixed $operator = null, mixed $value = null): self {
        if (is_callable($column)) $column($this);
        return $this;
    }
    public function whereExists(callable $cb): self {
        $sub = new self($this->database, 'subquery');
        $cb($sub);
        if ($sub->fromTable === 'users as current_actor') $this->guarded = true;
        return $this;
    }
    public function orWhereExists(callable $cb): self {
        $sub = new self($this->database, 'subquery');
        $cb($sub);
        if ($sub->fromTable === 'users as current_staff' && $sub->roleFilter) $this->staffGuarded = true;
        return $this;
    }
    public function from(string $table): self {$this->fromTable = $table;return $this;}
    public function selectRaw(string $sql): self {return $this;}
    public function whereIn(string $column, array $roles): self {
        $this->roleFilter = $column === 'current_staff.role' && in_array('moderator', $roles, true) && in_array('admin', $roles, true);
        return $this;
    }
    public function value(string $column): bool {
        if ($this->table !== 'plugin_states') throw new RuntimeException('Wrong plugin lookup');
        return true;
    }
    public function find(int $id): object {
        if ($this->table !== 'shouts') throw new RuntimeException('Wrong shout lookup');
        return (object)['id'=>$id,'user_id'=>$this->database->ownerId];
    }
    public function delete(): int {
        if ($this->table !== 'shouts') throw new RuntimeException('Wrong DELETE table');
        ++$this->database->deletes;
        // Faithful model of *guarded* delete, but NOT a database execution.
        // Without guard (old real controller), DELETE always goes through.
        if (!$this->guarded || ($this->database->databaseExists && !$this->database->databaseBanned
            && ($this->database->actorId === $this->database->ownerId
                || ($this->staffGuarded && in_array($this->database->databaseRole, ['moderator','admin'], true))))) {
            throw new DeleteRaceReached('DELETE reached');
        }
        return 0;
    }
}
$app = new class extends Container {
    public function abort(int $code, string $message = '', array $headers = []): never
    {throw new HttpException($code, $message, null, $headers);}
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$translator = new class {
    public function get(string $key): string {
        $catalog=json_decode(file_get_contents(dirname(__DIR__, 2).'/lang/en/server.json'), true, 64, JSON_THROW_ON_ERROR);
        return $catalog[$key] ?? throw new RuntimeException('Unknown '.$key);
    }
};
$app->instance('translator', $translator);
$db = new DeleteRaceDB();DB::swap($db);
$controller = new CommunityController();
$checks = 0;
foreach ([
    [1,1,'member','member',false,true,'delete','owner allowed'],
    [1,1,'member','member',true,true,403,'owner banned after request auth'],
    [1,1,'member','member',false,false,403,'owner deleted after request auth'],
    [2,1,'member','member',false,true,403,'foreign member forbidden'],
    [3,1,'moderator','moderator',false,true,'delete','current moderator allowed'],
    [3,1,'moderator','member',false,true,403,'moderator demoted after request auth'],
    [3,1,'moderator','moderator',true,true,403,'moderator banned after request auth'],
    [4,1,'admin','admin',false,true,'delete','current admin allowed'],
    [4,1,'admin','admin',true,true,403,'admin banned after request auth'],
] as [$actorId,$ownerId,$cachedRole,$dbRole,$dbBanned,$exists,$expected,$case]) {
    $db->databaseBanned=$dbBanned;$db->databaseExists=$exists;$db->databaseRole=$dbRole;
    $db->actorId=$actorId;$db->ownerId=$ownerId;$db->deletes=0;
    $account = new User();$account->id=$actorId;$account->role=$cachedRole;$account->is_banned=false;
    $request=Request::create('/api/shouts/25','DELETE');
    $request->setUserResolver(static fn()=>$account);
    try {$controller->deleteShout($request,25);$actual='unexpected-ok';}
    catch (DeleteRaceReached) {$actual='delete';}
    catch (HttpExceptionInterface $e) {$actual=$e->getStatusCode();}
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL $case: expected ".json_encode($expected)." actual ".json_encode($actual)." deletes=$db->deletes\n");exit(1);
    }
    ++$checks;
}
echo "PASS: $checks real controller stale-session/role guards with fake DB (NOT PDO/HTTP)\n";
