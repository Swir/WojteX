<?php
/**
 * Execute the REAL ForumController::deleteThread against a fake DB seam with a
 * changed current moderator row. This is not PHP PDO, HTTP or a concurrency test.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\ForumController;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ThreadDeleteCommitted extends RuntimeException {}
final class ThreadDeleteForumSeam {
    public static int $audit = 0;
    public static function isModerator(?User $user): bool {
        return $user && !$user->is_banned && in_array($user->role, ['moderator','admin'], true);
    }
    public static function thread(int $id, ?User $user): object {
        return (object) ['id' => $id, 'category_id' => 11];
    }
    public static function audit(string $action, array $detail): void {
        if ($action !== 'thread.deleted') throw new RuntimeException('wrong audit');
        ++self::$audit;
    }
}
// Only in this isolated smoke process: provide the three original service
// methods used by this controller, without loading a Laravel SQL connection.
class_alias(ThreadDeleteForumSeam::class, 'App\\Services\\ForumService');
final class ThreadDeleteFakeDB {
    public bool $exists = true;
    public bool $banned = false;
    public string $role = 'moderator';
    public int $actor = 1;
    public int $deleteAttempts = 0;
    public function table(string $name): ThreadDeleteFakeQuery {return new ThreadDeleteFakeQuery($this,$name);}
    public function transaction(callable $callback): mixed {return $callback();}
}
final class ThreadDeleteFakeQuery {
    public bool $guarded = false;
    private string $from = '';
    private bool $roleFilter = false;
    public function __construct(private ThreadDeleteFakeDB $db, private string $table) {}
    public function where(mixed $key, mixed $operator = null, mixed $value = null): self {return $this;}
    public function whereIn(string $column, mixed $values): self {
        if ($column === 'current_deleter.role') {
            $this->roleFilter = is_array($values) && in_array('admin', $values, true)
                && in_array('moderator', $values, true);
        }
        return $this;
    }
    public function whereExists(callable $callback): self {
        $nested = new self($this->db,'exists'); $callback($nested);
        if ($nested->from === 'users as current_deleter' && $nested->roleFilter) $this->guarded = true;
        return $this;
    }
    public function from(string $name): self {$this->from=$name;return $this;}
    public function selectRaw(string $expression): self {return $this;}
    public function select(string $expression): self {return $this;}
    public function pluck(string $expression): object {
        if ($this->table !== 'attachments') throw new RuntimeException('unexpected pluck');
        return new class {public function all(): array {return ['private/test-file'];}};
    }
    public function delete(): int {
        if ($this->table !== 'threads') throw new RuntimeException('not thread DELETE');
        ++$this->db->deleteAttempts;
        if (!$this->guarded) return 1; // Legacy unguarded SQL always deletes.
        return ($this->db->exists && !$this->db->banned
            && in_array($this->db->role,['admin','moderator'],true)) ? 1 : 0;
    }
}
final class ThreadDeleteDisk {
    public static int $deletedFiles=0;
    public function delete(array $paths): bool {
        self::$deletedFiles += count($paths);
        throw new ThreadDeleteCommitted('physical cleanup after authorized DB commit');
    }
}
final class ThreadDeleteStorage {
    public function disk(string $name): ThreadDeleteDisk {
        if ($name!=='local') throw new RuntimeException('wrong disk');
        return new ThreadDeleteDisk();
    }
}
$app=new class extends Container {
    public function abort(int $code, string $message = '', array $headers = []): never {
        throw new HttpException($code, $message, null, $headers);
    }
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$db=new ThreadDeleteFakeDB(); DB::swap($db); Storage::swap(new ThreadDeleteStorage());
$controller = new ForumController();
$assertions = 0;
foreach ([
    ['moderator','moderator',false,true,'deleted','current moderator'],
    ['moderator','member',false,true,403,'demoted after preflight'],
    ['moderator','moderator',true,true,403,'banned after preflight'],
    ['admin','admin',false,true,'deleted','current admin'],
    ['admin','admin',true,true,403,'banned admin after preflight'],
    ['admin','admin',false,false,403,'deleted account after preflight'],
] as [$cached,$current,$banned,$exists,$expected,$label]) {
    $db->role=$current; $db->banned=$banned; $db->exists=$exists; $db->deleteAttempts=0;
    ThreadDeleteForumSeam::$audit=0; ThreadDeleteDisk::$deletedFiles=0;
    $account=new User(); $account->id=1; $account->role=$cached; $account->is_banned=false;
    $request=Request::create('/api/threads/40','DELETE');
    $request->setUserResolver(static fn()=>$account);
    try {$controller->deleteThread($request, 40);$actual='unexpected success';}
    catch (ThreadDeleteCommitted) {$actual='deleted';}
    catch (HttpExceptionInterface $exception) {$actual=$exception->getStatusCode();}
    if ($actual !== $expected) {
        fwrite(STDERR,"RED: $label expected $expected got $actual\n");exit(1);
    }
    ++$assertions;
    $expectedAudit = $expected === 'deleted' ? 1 : 0;
    if (ThreadDeleteForumSeam::$audit !== $expectedAudit
        || ThreadDeleteDisk::$deletedFiles !== $expectedAudit) {
        fwrite(STDERR, "FAIL: $label audit/cleanup persisted without permission\n"); exit(1);
    }
    $assertions += 2;
}
echo "PASS: $assertions checks of actual ForumController with fake DB/storage (NOT PDO/HTTP)\n";
