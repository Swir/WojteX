<?php
/**
 * Actual AdminController category reassignment with deterministic competing
 * administrator. A fake DB models a shared locked row and mutable categories.
 * This is NOT PHP PDO/MySQL concurrency, HTTP, or true lock isolation.
 */
require dirname(__DIR__,2).'/vendor/autoload.php';

use App\Http\Controllers\AdminController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class CategoryRaceReply extends RuntimeException {}
final class CategoryRaceState {
    public static array $tree=[];
    public static bool $competingChange=false;
    public static bool $ranCompetingChange=false;
    public static bool $guard=false;
    public static bool $inTransaction=false;
    public static int $writes=0;
    public static int $audits=0;
    public static int $locks=0;
    public static function reset(bool $competing): void {
        self::$tree=[1=>null,2=>null,3=>null];
        self::$competingChange=$competing;
        self::$ranCompetingChange=false;
        self::$guard=self::$inTransaction=false;
        self::$writes=self::$audits=self::$locks=0;
    }
    public static function race(): void {
        if (self::$competingChange && !self::$ranCompetingChange) {
            // A second administrator moves category #2 under #1.
            self::$tree[2]=1;
            self::$ranCompetingChange=true;
        }
    }
    public static function acyclic(): bool {
        foreach (array_keys(self::$tree) as $start) {
            $seen=[]; $parent=$start;
            while ($parent!==null) {
                if (isset($seen[$parent])) return false;
                $seen[$parent]=true;
                $parent=self::$tree[$parent] ?? null;
            }
        }
        return true;
    }
}
final class CategoryRaceForum {
    public const COLORS = ['violet','cyan','amber','rose','green','blue'];
    public static function audit(string $action,array $details): void {
        if ($action!=='category.updated' || $details['category_id']!==1) throw new RuntimeException('Wrong audit');
        ++CategoryRaceState::$audits;
    }
    public static function categoryData(object $c): array {
        return ['id'=>$c->id,'parent_id'=>$c->parent_id];
    }
}
class_alias(CategoryRaceForum::class, 'App\\Services\\ForumService');
final class CategoryRaceDB {
    public function table(string $table): CategoryRaceQuery {return new CategoryRaceQuery($table);}
    public function transaction(callable $callback, int $attempts=1): mixed {
        if(CategoryRaceState::$inTransaction) throw new RuntimeException('Unexpected nested transaction');
        CategoryRaceState::$inTransaction=true;
        try {return $callback();}
        finally {CategoryRaceState::$guard=false;CategoryRaceState::$inTransaction=false;}
    }
}
final class CategoryRaceQuery {
    private ?int $id=null;
    private ?string $settingKey=null;
    public function __construct(private string $table) {}
    public function where(string $key,mixed $value): self {
        if ($key==='id') $this->id=(int)$value;
        elseif ($key==='key') $this->settingKey=(string)$value;
        else throw new RuntimeException('Unexpected where key: '.$key);
        return $this;
    }
    public function insertOrIgnore(array $values): int {
        if ($this->table!=='settings' || !isset($values['key'])) throw new RuntimeException('Bad lock insert');
        return 0; // Row created by earlier request, like an installed site.
    }
    public function lockForUpdate(): self {
        if ($this->table!=='settings' || !CategoryRaceState::$inTransaction || $this->settingKey===null)
            throw new RuntimeException('Missing transaction/lock key');
        if ($this->settingKey!=='category_hierarchy_mutation_lock') throw new RuntimeException('Unexpected lock key');
        // Competitor wins right BEFORE current request acquires shared lock.
        // Revised controller must re-evaluate the parent chain after acquiring.
        CategoryRaceState::race();
        CategoryRaceState::$guard=true;
        ++CategoryRaceState::$locks;
        return $this;
    }
    public function first(array $columns=['*']): ?object {
        if ($this->table==='settings') return CategoryRaceState::$guard?(object)['key'=>$this->settingKey]:null;
        if ($this->table==='categories') return $this->find($this->id??-1);
        throw new RuntimeException('Unexpected first: '.$this->table);
    }
    public function find(int $id): ?object {
        if ($this->table!=='categories') throw new RuntimeException('Unexpected find');
        if (!array_key_exists($id,CategoryRaceState::$tree)) return null;
        return (object)['id'=>$id,'parent_id'=>CategoryRaceState::$tree[$id], 'is_private'=>false];
    }
    public function update(array $data): int {
        if($this->table!=='categories' || $this->id===null) throw new RuntimeException('Unexpected update');
        if(!CategoryRaceState::$guard) CategoryRaceState::race();
        CategoryRaceState::$tree[$this->id]=$data['parent_id'];
        ++CategoryRaceState::$writes;
        return 1;
    }
}
final class CategoryRaceRequest extends Request {
    public static mixed $parent=2;
    public function validate(array $rules): array {return ['parent_id'=>self::$parent];}
}
$app=new class extends Container {
    public function abort(int $code,string $message='',array $headers=[]): never {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($code,$message,null,$headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class{public function get(string $key): string{return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,new class extends \Illuminate\Routing\ResponseFactory {
    public function __construct() {}
    public function json($data=[], $status=200, array $headers=[], $options=0) {
        throw new CategoryRaceReply('ok');
    }
});
DB::swap(new CategoryRaceDB());
$controller=new AdminController();
$checks=0;
function checkCategoryRace(bool $ok,string $description): void {
    global $checks;
    if(!$ok) {fwrite(STDERR,'RED: '.$description.'; tree='.json_encode(CategoryRaceState::$tree)
        .' writes='.CategoryRaceState::$writes.' locks='.CategoryRaceState::$locks."\n");exit(1);}
    ++$checks;
}
function requestCategory(AdminController $controller): int|string {
    $r=CategoryRaceRequest::create('/api/admin/categories/1','PATCH');
    $r->setUserResolver(static fn () => (object)['id'=>9,'role'=>'admin','is_banned'=>false]);
    try {$controller->category($r,1);return 'returned';}
    catch(CategoryRaceReply) {return 'ok';}
    catch(HttpExceptionInterface $e){return $e->getStatusCode();}
}
CategoryRaceState::reset(true);
$actual=requestCategory($controller);
checkCategoryRace($actual===422,'second administrator committed competing reparent before lock; expected 422, got '.var_export($actual,true));
checkCategoryRace(CategoryRaceState::$writes===0,'rejected hierarchy cannot write category');
checkCategoryRace(CategoryRaceState::$audits===0,'rejected hierarchy cannot audit success');
checkCategoryRace(CategoryRaceState::$ranCompetingChange,'competitor must actually change tree');
checkCategoryRace(CategoryRaceState::acyclic(),'competitor alone keeps tree acyclic');
checkCategoryRace(CategoryRaceState::$locks===1,'a shared hierarchy lock must be acquired');
CategoryRaceState::reset(false);
$actual=requestCategory($controller);
checkCategoryRace($actual==='ok','safe reparent should succeed');
checkCategoryRace(CategoryRaceState::$writes===1,'safe reparent persisted exactly once');
checkCategoryRace(CategoryRaceState::$audits===1,'safe reparent audited exactly once');
checkCategoryRace(CategoryRaceState::$locks===1,'safe reparent also guarded');
checkCategoryRace(CategoryRaceState::$tree[1]===2 && CategoryRaceState::acyclic(),'safe hierarchy stays acyclic');
echo "PASS: $checks real AdminController hierarchy fake-DB checks (NOT PDO/HTTP or true concurrency)\n";
