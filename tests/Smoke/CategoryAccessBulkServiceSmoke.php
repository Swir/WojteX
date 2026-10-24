<?php
/** Actual ForumService category visibility, create flags and live grants with fake DB.
 * This does not execute PDO, migrations or Laravel HTTP.
 */
require dirname(__DIR__,2).'/vendor/autoload.php';
use App\Models\User;
use App\Services\ForumService;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;

final class BulkCategoryFakeDB {
    public array $categories=[], $grants=[];
    public int $categoryScans=0,$grantScans=0;
    public function table(string $name):BulkCategoryFakeQuery{return new BulkCategoryFakeQuery($this,$name);}
}
final class BulkCategoryFakeSchema {
    public function hasTable(string $name):bool {
        if($name!=='category_group_permissions') throw new RuntimeException('Unexpected schema check');return true;
    }
}
final class BulkCategoryFakeQuery {
    private ?array $allowedIds=null;
    public function __construct(private BulkCategoryFakeDB $db,private string $table) {}
    public function join(...$args):self{return $this;}
    public function where(...$args):self{return $this;}
    public function whereColumn(...$args):self{return $this;}
    public function whereIn(string $field,array $ids):self{$this->allowedIds=$ids;return $this;}
    public function select(...$args):self{return $this;}
    public function selectRaw(...$args):self{return $this;}
    public function selectSub(...$args):self{return $this;}
    public function orderBy(...$args):self{return $this;}
    public function get(array $columns=['*']):\Illuminate\Support\Collection {
        if ($this->table==='categories') {
            ++$this->db->categoryScans;
            return collect($this->db->categories)->filter(fn($c)=>$this->allowedIds===null || in_array($c->id,$this->allowedIds,true))->values();
        }
        if ($this->table==='category_group_permissions as p') {
            ++$this->db->grantScans;
            return collect($this->db->grants);
        }
        throw new RuntimeException('Unexpected get table '.$this->table);
    }
}
function checkBulkService(bool $condition,string $label):void {
    $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
    if(!$condition){fwrite(STDERR,"FAIL: $label\n");exit(1);}
}
$app=new Container();Container::setInstance($app);Facade::setFacadeApplication($app);
$db=new BulkCategoryFakeDB;DB::swap($db);Schema::swap(new BulkCategoryFakeSchema);
$db->categories=[
  (object)['id'=>1,'parent_id'=>null,'is_private'=>false,'name'=>'Root','slug'=>'root','description'=>'','color'=>'violet','icon'=>'folder','sort_order'=>0],
  (object)['id'=>2,'parent_id'=>1,'is_private'=>true,'name'=>'Private','slug'=>'private','description'=>'','color'=>'violet','icon'=>'folder','sort_order'=>1],
  (object)['id'=>3,'parent_id'=>2,'is_private'=>true,'name'=>'Child','slug'=>'child','description'=>'','color'=>'violet','icon'=>'folder','sort_order'=>2],
  (object)['id'=>4,'parent_id'=>2,'is_private'=>false,'name'=>'Public below private','slug'=>'nested','description'=>'','color'=>'violet','icon'=>'folder','sort_order'=>3],
  (object)['id'=>5,'parent_id'=>88,'is_private'=>false,'name'=>'Broken','slug'=>'broken','description'=>'','color'=>'violet','icon'=>'folder','sort_order'=>4],
];
$db->grants=[
 (object)['category_id'=>2,'allow_view'=>1,'allow_create'=>0,'allow_reply'=>1],
 (object)['category_id'=>3,'allow_view'=>1,'allow_create'=>1,'allow_reply'=>1],
];
$user=new User();$user->id=17;$user->role='member';$user->is_banned=false;
$visible=ForumService::visibleCategoryIds($user);
checkBulkService($visible===[1,2,3,4],'member: visible private ancestors + public child only');
checkBulkService($db->categoryScans===1 && $db->grantScans===1,'member: one category/grant scan');
$db->categoryScans=$db->grantScans=0;
$cards=ForumService::categories($user);
checkBulkService(count($cards)===4,'member: category listing excludes broken room');
$creates=array_column($cards,'can_create','id');
checkBulkService($creates===[1=>true,2=>false,3=>true,4=>true],'member: create flags use full hierarchy');
checkBulkService($db->categoryScans===2 && $db->grantScans===1,'listing loads category tree once and visible cards once');
$db->grants=[(object)['category_id'=>3,'allow_view'=>1,'allow_create'=>1,'allow_reply'=>1]];
checkBulkService(ForumService::visibleCategoryIds($user)===[1],'revoked parent view immediately blocks child, no stale cache');
checkBulkService(ForumService::visibleCategoryIds(null)===[1],'guest: no private-parent leakage');
$staff=new User();$staff->id=1;$staff->role='moderator';$staff->is_banned=false;
checkBulkService(ForumService::visibleCategoryIds($staff)===[1,2,3,4,5],'moderator existing override retained');
$staff->is_banned=true;
checkBulkService(ForumService::visibleCategoryIds($staff)===[],'banned moderator not authorized');
echo 'PASS: '.($GLOBALS['checks']??0).' actual ForumService tests with fake DB (not PDO/HTTP)'.PHP_EOL;
