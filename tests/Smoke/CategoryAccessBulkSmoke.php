<?php
/** Deep MyBB category trees: quantify actual ancestor reads on the old engine,
 * then require the production bulk planner to preserve every authorization.
 * Pure PHP instrumentation; not Laravel HTTP/PDO/MySQL/concurrency.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';
use App\Services\CategoryAccess;

final class CountedCategory {
    public static int $reads=0;
    public function __construct(private array $columns) {}
    public function __get(string $key): mixed {self::$reads++;return $this->columns[$key]??null;}
}
function assertion(bool $ok,string $reason):void {
    $GLOBALS['checks']=($GLOBALS['checks']??0)+1;
    if (!$ok) {fwrite(STDERR,"RED: $reason\n");exit(1);}
}
$size=1800;
$chain=[];
for($id=1;$id<=$size;$id++) $chain[$id]=new CountedCategory(['id'=>$id,'parent_id'=>$id===1?null:$id-1,'is_private'=>false]);
CountedCategory::$reads=0;
for($id=1;$id<=$size;$id++) {
    if (!CategoryAccess::allows($chain,$id,[],true)) throw new RuntimeException('Public chain unexpectedly hidden');
}
$baseline=CountedCategory::$reads;
if (($argv[1]??'')==='baseline') {
    fwrite(STDERR,"RED-before: unmodified CategoryAccess::allows scanned $baseline category properties for $size public rooms (quadratic duplicate ancestor walks).\n");
    // Expect no more than 20 reads per room after bulk planning: the old path fails.
    exit($baseline <= $size*20?0:1);
}

CountedCategory::$reads=0;
$bulk=CategoryAccess::accessMap($chain,[],true);
$bulkReads=CountedCategory::$reads;
assertion(count($bulk)===$size,'bulk category coverage');
foreach($bulk as $id=>$abilities) {
    assertion($abilities['view']===true && $abilities['create']===true && $abilities['reply']===true, "public $id grants changed");
}
assertion($bulkReads <= $size*20,"bulk ancestor reads $bulkReads exceeded linear limit ".($size*20));
// Exhaustive parity against the existing single-target authority, including
// nested private ancestors, cycles, missing parents and guest mode.
$sample=[
 1=>(object)['id'=>1,'parent_id'=>null,'is_private'=>false],
 2=>(object)['id'=>2,'parent_id'=>1,'is_private'=>true],
 3=>(object)['id'=>3,'parent_id'=>2,'is_private'=>false],
 4=>(object)['id'=>4,'parent_id'=>2,'is_private'=>true],
 5=>(object)['id'=>5,'parent_id'=>99,'is_private'=>false],
 6=>(object)['id'=>6,'parent_id'=>7,'is_private'=>false],
 7=>(object)['id'=>7,'parent_id'=>6,'is_private'=>false],
 8=>(object)['id'=>8,'parent_id'=>6,'is_private'=>true],
 9=>(object)['id'=>9,'parent_id'=>null,'is_private'=>true],
 10=>(object)['id'=>10,'parent_id'=>9,'is_private'=>true],
 11=>(object)['id'=>11,'parent_id'=>10,'is_private'=>false],
];
$grants=[2=>['view'=>true,'create'=>false,'reply'=>true],4=>['view'=>true,'create'=>true,'reply'=>true],9=>['view'=>true,'create'=>false,'reply'=>false],10=>['view'=>false,'create'=>true,'reply'=>true]];
foreach([true,false] as $member){
 foreach([$grants,[],[2=>['view'=>true,'create'=>true,'reply'=>false],9=>['view'=>true,'create'=>true,'reply'=>true],10=>['view'=>true,'create'=>false,'reply'=>true]]] as $rights) {
  $matrix=CategoryAccess::accessMap($sample,$rights,$member);
  foreach($sample as $id=>$category) foreach(['view','create','reply'] as $ability) {
    assertion(($matrix[$id][$ability]??null)===CategoryAccess::allows($sample,$id,$rights,$member,$ability), "policy drift category $id ability $ability member ".(int)$member);
  }
 }
}
$service=file_get_contents(dirname(__DIR__,2).'/app/Services/ForumService.php');
assertion(substr_count($service, 'CategoryAccess::accessMap(')>=2,'both production visibleCategoryIds and categories must use bulk authorization');
echo 'PASS: '.($GLOBALS['checks']??0).' pure PHP deep-category bulk and policy parity checks; old ancestor property reads='.$baseline.' new='.$bulkReads." (NOT PDO/HTTP/MySQL)\n";
