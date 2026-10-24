<?php
/**
 * Exercises real ForumController::moderate with a cached request role and
 * mutable authoritative DB role. Deterministic fake DB, NOT PDO/HTTP.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\ForumController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ModerationFinished extends RuntimeException {}
final class ModerateForumSeam
{
    public static int $audits = 0;
    public static function isModerator(?object $user): bool
    {
        return $user !== null && !$user->is_banned && in_array($user->role, ['admin','moderator'], true);
    }
    public static function thread(int $id, ?object $user): object
    {
        return (object)['id'=>$id, 'category_id'=>13, 'is_locked'=>false, 'is_pinned'=>false];
    }
    public static function threadData(object $thread): array
    {
        throw new ModerationFinished('authorized moderation completed');
    }
    public static function audit(string $action, array $detail): void
    {
        if ($action !== 'thread.moderated' || $detail['thread_id'] !== 44)
            throw new RuntimeException('incorrect audit event');
        ++self::$audits;
    }
}
class_alias(ModerateForumSeam::class, 'App\\Services\\ForumService');
final class ModerateDbSeam
{
    public bool $accountExists = true;
    public bool $banned = false;
    public string $role = 'moderator';
    public bool $threadExists = true;
    public int $attempts = 0;
    public int $writes = 0;
    public int $guardedAttempts = 0;
    public bool $locked = false;
    public bool $pinned = false;
    public function table(string $name): ModerateQuerySeam { return new ModerateQuerySeam($this, $name); }
}
final class ModerateQuerySeam
{
    private bool $hasRoleCheck = false;
    private string $subqueryFrom = '';
    private array $where = [];
    private bool $guarded = false;
    public function __construct(private ModerateDbSeam $db, private string $table) {}
    public function where(string $field, mixed $operator=null, mixed $value=null): self
    {
        $this->where[$field] = $value === null ? $operator : $value;
        return $this;
    }
    public function whereIn(string $field, array $values): self
    {
        if ($field === 'current_moderator.role') {
            $this->hasRoleCheck = $values === ['admin', 'moderator'];
        }
        return $this;
    }
    public function whereExists(callable $nested): self
    {
        $q = new self($this->db, 'subquery');
        $nested($q);
        if ($q->subqueryFrom === 'users as current_moderator'
            && $q->hasRoleCheck
            && ($q->where['current_moderator.id'] ?? null) === 7
            && ($q->where['current_moderator.is_banned'] ?? null) === false) {
            $this->guarded = true;
        }
        return $this;
    }
    public function from(string $from): self { $this->subqueryFrom=$from; return $this; }
    public function selectRaw(string $expression): self { return $this; }
    public function exists(): bool
    {
        if (!$this->guarded || !$this->db->threadExists || !$this->db->accountExists
            || $this->db->banned || !in_array($this->db->role, ['admin', 'moderator'], true)) return false;
        foreach (['is_locked'=>'locked','is_pinned'=>'pinned'] as $column=>$property) {
            if (array_key_exists($column, $this->where)
                && (bool)$this->where[$column] !== $this->db->$property) return false;
        }
        return true;
    }
    public function update(array $data): int
    {
        if ($this->table !== 'threads' || (($this->where['id'] ?? $this->where['threads.id'] ?? null) !== 44)
            || array_keys($data) !== ['is_locked', 'is_pinned'])
            throw new RuntimeException('wrong moderation SQL');
        ++$this->db->attempts;
        if ($this->guarded) ++$this->db->guardedAttempts;
        $allowed = !$this->guarded || ($this->db->accountExists && !$this->db->banned
            && in_array($this->db->role, ['admin','moderator'], true));
        if (!$this->db->threadExists || !$allowed) return 0;
        // Emulate MySQL affected-row semantics for same-value UPDATE.
        if ($this->db->locked === (bool)$data['is_locked']
            && $this->db->pinned === (bool)$data['is_pinned']) return 0;
        ++$this->db->writes;
        $this->db->locked = $data['is_locked'];
        $this->db->pinned = $data['is_pinned'];
        return 1;
    }
}
final class ModerateRequest extends Request
{
    public function validate(array $rules): array {return ['is_locked'=>true,'is_pinned'=>true];}
}
$app = new class extends Container {
    public function abort(int $code, string $message='', array $headers=[]): never
    { throw new HttpException($code, $message, null, $headers); }
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$app->instance('translator', new class {public function get(string $key): string {return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class extends \Illuminate\Routing\ResponseFactory {
    public function __construct() {}
    public function json($data = [], $status = 200, array $headers = [], $options = 0) {
        throw new ModerationFinished();
    }
});
$db=new ModerateDbSeam(); DB::swap($db);
$controller=new ForumController();
$checks=0;
foreach ([
    ['moderator', 'member', false, true, true, 403, 'demoted moderator after preflight'],
    ['moderator', 'moderator', true, true, true, 403, 'banned moderator after preflight'],
    ['admin', 'admin', true, true, true, 403, 'banned admin after preflight'],
    ['admin', 'member', false, true, true, 403, 'demoted admin after preflight'],
    ['admin', 'admin', false, false, true, 403, 'deleted account after preflight'],
    ['moderator', 'moderator', false, true, false, 403, 'thread removed after preflight'],
    ['moderator', 'moderator', false, true, true, 'success', 'live moderator'],
    ['admin', 'admin', false, true, true, 'success', 'live admin'],
    ['member', 'member', false, true, true, 403, 'non-staff preflight'],
    ['moderator', 'moderator', false, true, true, 'success', 'authorized no-op does not audit', true],
    ['moderator', 'member', false, true, true, 403, 'demoted no-op still denied', true],
] as [$cachedRole,$currentRole,$banned,$exists,$threadExists,$want,$label]) {
    $db->role=$currentRole; $db->banned=$banned; $db->accountExists=$exists;
    $db->threadExists=$threadExists; $db->attempts=0; $db->writes=0; $db->guardedAttempts=0;
    $db->locked=$db->pinned=(bool)($test[7] ?? false); ModerateForumSeam::$audits=0;
    $request=ModerateRequest::create('/api/threads/44/moderate','PATCH');
    $request->setUserResolver(static fn() => (object)['id'=>7,'role'=>$cachedRole,'is_banned'=>false]);
    try {$controller->moderate($request,44);$actual='unexpected return';}
    catch (ModerationFinished) {$actual='success';}
    catch (HttpExceptionInterface $e) {$actual=$e->getStatusCode();}
    if ($actual !== $want) {
        fwrite(STDERR, "RED: $label expected $want got $actual; writes={$db->writes}, audits=".ModerateForumSeam::$audits."\n");exit(1);
    }
    ++$checks;
    $noop = (bool)($test[7] ?? false);
    $expectedWrites = $want === 'success' && !$noop ? 1 : 0;
    $expectedState = $want === 'success' || $noop;
    if ($db->writes !== $expectedWrites || ModerateForumSeam::$audits !== $expectedWrites
        || $db->locked !== $expectedState || $db->pinned !== $expectedState) {
        fwrite(STDERR,"FAIL: unauthorized DB mutation or audit on $label\n");exit(1);
    }
    $checks += 4;
    if ($cachedRole !== 'member' && ($db->attempts !== 1 || $db->guardedAttempts !== 1)) {
        fwrite(STDERR,"FAIL: no guarded update on $label\n");exit(1);
    }
    ++$checks;
}
echo "PASS: $checks checks on real ForumController with fake DB (NOT PDO/HTTP)\n";
