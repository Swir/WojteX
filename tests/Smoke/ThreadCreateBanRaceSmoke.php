<?php
/**
 * Actual ForumController::create with a fake DB seam that changes the persisted
 * ban after request authorization but before INSERT. Not HTTP, PDO, or true
 * concurrency. Refusal must happen before either thread or post is written.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\ForumController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ThreadCreatePostCommitted extends RuntimeException {}
class ThreadCreateForumSeam {
    public static function canSeeCategory(object $category, ?object $user): bool {return $user !== null && !$user->is_banned;}
    public static function canCreateCategory(object $category, ?object $user): bool {return $user !== null && !$user->is_banned;}
}
class_alias(ThreadCreateForumSeam::class, 'App\\Services\\ForumService');
class ThreadCreateDBSeam {
    public bool $currentExists = true;
    public bool $currentBan = false;
    public int $threadWrites = 0;
    public int $postWrites = 0;
    public int $attempts = 0;
    public bool $guarded = false;
    public function transaction(callable $callback): mixed {return $callback();}
    public function table(string $table): ThreadCreateQuerySeam {return new ThreadCreateQuerySeam($this,$table);}
    public function connection(): object {return new class { public function getPdo(): object {return new class {public function lastInsertId(): string {return '42';}};}};}
}
class ThreadCreateQuerySeam {
    public array $where = [];
    public ?string $selection = null;
    public array $bindings = [];
    public function __construct(private ThreadCreateDBSeam $db, public string $table) {}
    public function where(string $column, mixed $a, mixed $b = null): self {$this->where[$column] = $b === null ? $a : $b;return $this;}
    public function selectRaw(string $sql, array $bindings=[]): self {$this->selection=$sql;$this->bindings=$bindings;return $this;}
    public function find(int $id): ?object {if ($this->table !== 'categories') throw new RuntimeException('Unexpected find');return (object)['id'=>$id,'is_private'=>false];}
    public function insertGetId(array $row): int {
        if ($this->table !== 'threads') throw new RuntimeException('Unexpected old insert');
        ++$this->db->attempts; ++$this->db->threadWrites;
        return 42;
    }
    public function insertUsing(array $columns, mixed $query): int {
        if ($this->table !== 'threads' || !$query instanceof self || $query->table !== 'users'
            || $columns !== ['category_id','user_id','title','slug','created_at','updated_at']
            || $query->where['users.id'] !== 7 || $query->where['users.is_banned'] !== false
            || !str_contains($query->selection ?? '', 'users.id AS user_id')
            || count($query->bindings) !== 5) {
            throw new RuntimeException('Unprotected INSERT...SELECT');
        }
        $this->db->guarded = true;
        ++$this->db->attempts;
        if (!$this->db->currentExists || $this->db->currentBan) return 0;
        ++$this->db->threadWrites;
        return 1;
    }
    public function insert(array $row): never {
        if ($this->table !== 'posts' || $row['thread_id'] !== 42 || $row['user_id'] !== 7) throw new RuntimeException('Malformed reply');
        ++$this->db->postWrites;
        throw new ThreadCreatePostCommitted();
    }
}
class ThreadCreateRequest extends Request {
    public function validate(array $rules): array {return ['category_id'=>5,'title'=>'Safe thread test','body'=>'Test body'];}
}
$app = new class extends Container {
    public function abort(int $code, string $message='', array $headers=[]): never {throw new HttpException($code,$message,null,$headers);}
};
Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('translator', new class {
    public function get(string $key): string {return $key;}
});
$db = new ThreadCreateDBSeam(); DB::swap($db);
$controller = new ForumController(); $checks=0;
foreach ([
    [false,true,true,403,'ban committed after request preflight'],
    [false,false,true,'written','active user can create'],
    [false,false,false,403,'account deleted after request preflight'],
    [true,false,true,404,'cached banned account denied before insert'],
] as [$cachedBan,$ban,$exists,$expected,$label]) {
    $db->currentBan=$ban;$db->currentExists=$exists;$db->attempts=0;$db->threadWrites=0;$db->postWrites=0;$db->guarded=false;
    $request=ThreadCreateRequest::create('/api/threads','POST');
    $request->setUserResolver(static fn()=> (object)['id'=>7,'is_banned'=>$cachedBan]);
    try {$controller->create($request); $actual='unexpected success';}
    catch (ThreadCreatePostCommitted) {$actual='written';}
    catch (HttpExceptionInterface $e) {$actual=$e->getStatusCode();}
    if ($actual !== $expected) {fwrite(STDERR,"RED: $label expected ".json_encode($expected)." got ".json_encode($actual)." (threads=$db->threadWrites posts=$db->postWrites)\n");exit(1);}
    ++$checks;
    $wantsWrite=$expected==='written' ? 1:0;
    if ($db->threadWrites !== $wantsWrite || $db->postWrites !== $wantsWrite) {fwrite(STDERR,"FAIL: unexpected write on $label\n");exit(1);}
    $checks+=2;
    if (!$cachedBan && !$db->guarded) {fwrite(STDERR,"FAIL: missing guarded SQL on $label\n");exit(1);}
    ++$checks;
}
echo "PASS: $checks checks of actual ForumController with fake DB (NOT PDO/HTTP)\n";
