<?php
/**
 * Execute the actual ForumController::editPost through a deterministic DB seam.
 * Flip live account/role/thread state after stale preflight; no PDO/HTTP claim.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\ForumController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PostEditAccepted extends RuntimeException {}
final class PostEditForumSeam {
    public static bool $cachedLocked = false;
    public static int $audits = 0;
    public static function thread(int $id, ?object $actor): object {
        return (object)['id'=>$id,'category_id'=>17,'is_locked'=>self::$cachedLocked];
    }
    public static function canReplyCategory(object $category, ?object $actor): bool {
        return $actor !== null && !$actor->is_banned;
    }
    public static function isModerator(?object $actor): bool {
        return $actor && !$actor->is_banned && in_array($actor->role, ['admin','moderator'], true);
    }
    public static function audit(string $event, array $detail): void {
        if ($event !== 'post.edited' || $detail['post_id'] !== 88) throw new RuntimeException('bad audit');
        ++self::$audits;
    }
    public static function postData(object $post, ?object $actor): array {return ['id'=>$post->id];}
}
class_alias(PostEditForumSeam::class, 'App\\Services\\ForumService');
final class PostEditFakeDB {
    public bool $currentUserExists=true;
    public bool $currentBanned=false;
    public string $currentRole='member';
    public bool $currentLocked=false;
    public bool $currentThreadExists=true;
    public bool $currentPostExists=true;
    public int $postOwner=7;
    public int $updateAttempts=0;
    public int $actualUpdates=0;
    public int $guardedAttempts=0;
    public function table(string $table): PostEditFakeQuery {return new PostEditFakeQuery($this, $table);}
}
final class PostEditFakeQuery {
    private array $clauses=[];
    private string $source='';
    private string $joined='';
    private bool $liveRole=false;
    private bool $owner=false;
    private bool $lock=false;
    private bool $exists=false;
    private bool $checkedGuard=false;
    public function __construct(private PostEditFakeDB $db, private string $table) {}
    public function find(int $id): ?object {
        if ($this->table === 'posts') return (object)['id'=>$id,'thread_id'=>33,'user_id'=>$this->db->postOwner];
        if ($this->table === 'categories') return (object)['id'=>17,'is_private'=>false];
        throw new RuntimeException('unexpected find '.$this->table);
    }
    public function where(mixed $column, mixed $operator=null, mixed $value=null): self {
        if (is_callable($column)) {$column($this);return $this;}
        $actual=$value===null ? $operator : $value;
        $this->clauses[$column]=$actual;
        if ($column==='live_thread.is_locked' && $actual===false) $this->lock=true;
        return $this;
    }
    public function from(string $source): self {$this->source=$source;return $this;}
    public function join(string $table, string $first, string $operator, string $second): self {
        $this->joined=$table.':'.$first.$operator.$second;return $this;
    }
    public function selectRaw(string $sql): self {return $this;}
    public function whereIn(string $column, array $values): self {
        if ($column==='live_editor.role' && $values===['admin','moderator']) $this->liveRole=true;
        return $this;
    }
    public function whereColumn(string $column, string $comparison): self {
        if ($column==='p.user_id' && $comparison==='live_editor.id') $this->owner=true;
        return $this;
    }
    public function orWhere(callable $callback): self {$callback($this);return $this;}
    public function whereExists(callable $callback): self {
        $child=new self($this->db, 'exists');$callback($child);
        $this->checkedGuard=$child->source==='users as live_editor'
            && $child->joined==='threads as live_thread:live_thread.id=p.thread_id'
            && ($child->clauses['live_editor.id']??null)===7
            && ($child->clauses['live_editor.is_banned']??null)===false
            && $child->liveRole && $child->owner && $child->lock;
        return $this;
    }
    public function update(array $data): int {
        if (!in_array($this->table,['posts','posts as p'],true) || ($data['body']??null)!=='Edited body' || !isset($data['updated_at'])) {
            throw new RuntimeException('unexpected UPDATE');
        }
        ++$this->db->updateAttempts;
        $guarded=$this->table==='posts as p' && $this->checkedGuard && ($this->clauses['p.id']??null)===88;
        if ($guarded) ++$this->db->guardedAttempts;
        // Before the fix the original unguarded UPDATE always succeeds.
        $authorized=$guarded ? ($this->db->currentPostExists && $this->db->currentThreadExists
            && $this->db->currentUserExists && !$this->db->currentBanned
            && (in_array($this->db->currentRole,['admin','moderator'],true)
                || ($this->db->postOwner===7 && !$this->db->currentLocked))) : true;
        if (!$authorized) return 0;
        ++$this->db->actualUpdates;return 1;
    }
}
final class PostEditRequest extends Request {
    public function validate(array $rules): array {return ['body'=>'Edited body'];}
}
$app=new class extends Container {
    public function abort(int $code, string $message='', array $headers=[]): never {
        throw new HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string {return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class, new class extends \Illuminate\Routing\ResponseFactory {
    public function __construct() {}
    public function json($data = [], $status = 200, array $headers = [], $options = 0): never {throw new PostEditAccepted();}
});
$db=new PostEditFakeDB();DB::swap($db);
$controller=new ForumController();$checks=0;
foreach ([
    // cached role, post owner, current role, banned, locked, exists user, thread, post, expected, label
    ['member',7,'member',true,false,true,true,true,403,'owner banned after preflight'],
    ['member',7,'member',false,false,true,true,true,'written','active owner edits own unlocked post'],
    ['member',7,'member',false,true,true,true,true,403,'thread locked after preflight'],
    ['moderator',99,'member',false,false,true,true,true,403,'moderator demoted after preflight'],
    ['moderator',99,'member',false,true,true,true,true,403,'demoted moderator of locked thread'],
    ['admin',99,'admin',false,true,true,true,true,'written','active admin edits locked thread'],
    ['moderator',99,'moderator',false,true,true,true,true,'written','active moderator edits locked thread'],
    ['moderator',99,'moderator',true,false,true,true,true,403,'moderator banned after preflight'],
    ['member',7,'member',false,false,false,true,true,403,'owner account deleted after preflight'],
    ['member',7,'member',false,false,true,false,true,403,'thread removed after preflight'],
    ['member',7,'member',false,false,true,true,false,403,'post removed after preflight'],
    ['member',99,'member',false,false,true,true,true,403,'unprivileged non-owner denied at preflight'],
] as [$cached,$owner,$current,$banned,$locked,$userExists,$threadExists,$postExists,$expected,$label]) {
    $db->postOwner=$owner;$db->currentRole=$current;$db->currentBanned=$banned;
    $db->currentLocked=$locked;$db->currentUserExists=$userExists;
    $db->currentThreadExists=$threadExists;$db->currentPostExists=$postExists;
    $db->actualUpdates=0;$db->updateAttempts=0;$db->guardedAttempts=0;
    PostEditForumSeam::$cachedLocked=false;PostEditForumSeam::$audits=0;
    $request=PostEditRequest::create('/api/posts/88','PATCH');
    $request->setUserResolver(static fn()=>(object)['id'=>7,'role'=>$cached,'is_banned'=>false]);
    try {$controller->editPost($request,88);$actual='unexpected success';}
    catch(PostEditAccepted){$actual='written';}
    catch(HttpExceptionInterface $exception){$actual=$exception->getStatusCode();}
    if ($actual!==$expected) {
        fwrite(STDERR,"RED: $label expected ".json_encode($expected)." got ".json_encode($actual)
            ."; updates={$db->actualUpdates}, audit=".PostEditForumSeam::$audits."\n");exit(1);
    }
    ++$checks;
    $expectedWrites=$expected==='written'?1:0;
    if ($db->actualUpdates!==$expectedWrites || PostEditForumSeam::$audits!==$expectedWrites) {
        fwrite(STDERR,"FAIL: wrong write/audit on $label\n");exit(1);
    }
    $checks+=2;
    if ($label!=='unprivileged non-owner denied at preflight' && $db->guardedAttempts!==1) {
        fwrite(STDERR,"FAIL: no guarded write for $label\n");exit(1);
    }
    ++$checks;
}
echo "PASS: $checks assertions on real ForumController with fake DB (NOT PDO/HTTP)\n";
