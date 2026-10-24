<?php
/**
 * Execute the ACTUAL PrivateMessageReportAdminController with mutable DB
 * doubles. RED on dev.31: a stale authenticated admin can still read and
 * change a reported private message after their role is revoked. Not HTTP/PDO.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\PrivateMessageReportAdminController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ReportAdminJson extends RuntimeException
{
    public function __construct(public array $body) {parent::__construct('ok');}
}
final class ReportAdminState
{
    public static array $users = [];
    public static array $reports = [];
    public static int $audit = 0;
    public static int $writes = 0;
    public static $beforeReportExists = null;
    public static $beforePage = null;
    public static function reset(): void
    {
        self::$users = [1 => ['role' => 'admin', 'is_banned' => false]];
        self::$reports = [42 => ['status' => 'open', 'reviewed_by' => null, 'reviewed_at' => null]];
        self::$audit = self::$writes = 0;
        self::$beforeReportExists = self::$beforePage = null;
    }
    public static function liveAdmin(): bool
    {
        return isset(self::$users[1]) && self::$users[1]['role'] === 'admin'
            && self::$users[1]['is_banned'] === false;
    }
}
final class ReportAdminForumDouble
{
    public static function audit(string $event, array $details): void
    {
        if ($event !== 'message.report.reviewed' || ($details['report_id'] ?? 0) !== 42
            || !in_array($details['status'] ?? '', ['open','resolved','dismissed'], true)) {
            throw new RuntimeException('Invalid or private audit fields');
        }
        ++ReportAdminState::$audit;
    }
}
class_alias(ReportAdminForumDouble::class, 'App\\Services\\ForumService');
final class ReportAdminDB
{
    public function table(string $table): ReportAdminQuery {return new ReportAdminQuery($table);}
    public function transaction(callable $callback): mixed {return $callback();}
}
final class ReportAdminPage
{
    public function __construct(private array $items) {}
    public function items(): array {return $this->items;}
    public function currentPage(): int {return 1;}
    public function lastPage(): int {return count($this->items) ? 1 : 0;}
}
final class ReportAdminQuery extends \Illuminate\Database\Query\Builder
{
    private array $conditions = [];
    private array $existing = [];
    private string $source;
    public function __construct(string $source) {$this->source = $source;}
    public function where($name, $op = null, $value = null, $boolean = 'and'): self
    {
        if ($value === null) {$value=$op;$op='=';}
        $this->conditions[] = [$name,$op,$value];return $this;
    }
    public function selectRaw($sql, array $bindings = []): self {return $this;}
    public function from($table, $as = null): self {$this->source = $table;return $this;}
    public function whereExists($cb, $boolean = 'and', $not = false): self
    {
        $q = new self('exists');$cb($q);$this->existing[]=$q;return $this;
    }
    private function conditionsOk(array $row, int $id): bool
    {
        foreach ($this->conditions as [$col,$op,$wanted]) {
            $key=substr($col,strrpos($col,'.')===false?0:strrpos($col,'.')+1);
            $actual=$key==='id'?$id:($row[$key]??null);
            $equal=$actual == $wanted;
            if ($op==='=' && !$equal || $op==='!=' && $equal) return false;
        }
        foreach ($this->existing as $subquery) if (!$subquery->matching()) return false;
        return true;
    }
    private function matching(): bool
    {
        if ($this->source==='users' || $this->source==='users as live_admin') {
            foreach (ReportAdminState::$users as $id=>$row) {
                if ($this->conditionsOk($row,(int)$id)) return true;
            }
            return false;
        }
        if (str_starts_with($this->source,'private_message_reports')) {
            foreach (ReportAdminState::$reports as $id=>$row) {
                if ($this->conditionsOk($row,(int)$id)) return true;
            }
            return false;
        }
        throw new RuntimeException('Unknown table '.$this->source);
    }
    public function exists(): bool
    {
        if ($this->source==='private_message_reports' && ReportAdminState::$beforeReportExists) {
            $callback=ReportAdminState::$beforeReportExists;
            ReportAdminState::$beforeReportExists=null;
            $callback();
        }
        return $this->matching();
    }
    public function update(array $values): int
    {
        if ($this->source!=='private_message_reports') throw new RuntimeException('Wrong update table');
        if (!$this->matching()) return 0;
        $id=42;$row=ReportAdminState::$reports[$id];
        if ($row['status']===$values['status'] && $row['reviewed_by']===$values['reviewed_by']) return 0;
        ReportAdminState::$reports[$id] = array_merge($row,$values);
        ++ReportAdminState::$writes;
        return 1;
    }
    public function join($table, $first, $op = null, $second = null, $type = 'inner', $where = false): self {return $this;}
    public function orderByDesc($column): self {return $this;}
    public function select($columns = ['*']): self {return $this;}
    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null, $total = null): ReportAdminPage
    {
        if (ReportAdminState::$beforePage) {
            $callback=ReportAdminState::$beforePage;ReportAdminState::$beforePage=null;$callback();
        }
        $data=$this->matching() ? [(object)['id'=>42,'body'=>'SECRET PRIVATE PM']] : [];
        return new ReportAdminPage($data);
    }
}
final class ReportAdminRequest extends Request
{
    public static string $desired = 'resolved';
    public function validate(array $rules): array
    {
        return $this->getMethod()==='PATCH' ? ['status'=>self::$desired] : [];
    }
}
$app = new class extends Container {
    public function abort(int $code, string $message='', array $headers=[]): never {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($code, $message, null, $headers);
    }
};
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string {return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json($data = [], $status = 200, array $headers = [], $options = 0) {
            throw new ReportAdminJson($data);
        }
    });
DB::swap(new ReportAdminDB());
$controller=new PrivateMessageReportAdminController();
$checks=0;
function check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {fwrite(STDERR, 'RED: '.$label.'; writes='.ReportAdminState::$writes.' audit='.ReportAdminState::$audit."\n");exit(1);}
    ++$checks;
}
function runReportAction(PrivateMessageReportAdminController $controller, string $method): array
{
    $request=ReportAdminRequest::create('/api/admin/message-reports', $method);
    $request->setUserResolver(static fn () => (object)['id'=>1, 'role'=>'admin','is_banned'=>false]);
    try {
        if ($method==='GET') $controller->index($request);
        else $controller->review($request,42);
        return ['status'=>'returned'];
    } catch (ReportAdminJson $e) {
        return ['status'=>200, 'body'=>$e->body];
    } catch (HttpExceptionInterface $e) {
        return ['status'=>$e->getStatusCode()];
    }
}
$denials = [
    'role revoked before review' => static function () {ReportAdminState::$users[1]['role']='member';},
    'admin banned before review' => static function () {ReportAdminState::$users[1]['is_banned']=true;},
    'admin removed before review' => static function () {unset(ReportAdminState::$users[1]);},
];
foreach ($denials as $label=>$change) {
    ReportAdminState::reset();
    ReportAdminState::$beforeReportExists=$change;
    $result=runReportAction($controller,'PATCH');
    check($result['status']===403, "$label expected 403 got ".var_export($result['status'],true));
    check(ReportAdminState::$reports[42]['status']==='open',"$label must not change report");
    check(ReportAdminState::$writes===0,"$label no DB update");
    check(ReportAdminState::$audit===0,"$label no false audit");
}
ReportAdminState::reset();$result=runReportAction($controller,'PATCH');
check($result['status']===200, 'active admin reviews');
check(ReportAdminState::$reports[42]['status']==='resolved','resolved saved');
check(ReportAdminState::$reports[42]['reviewed_by']===1,'reviewer saved');
check(ReportAdminState::$audit===1,'transition audited');
check(ReportAdminState::$writes===1,'one transition write');
$result=runReportAction($controller,'PATCH');
check($result['status']===200,'idempotent repeat accepted');
check(ReportAdminState::$writes===1,'repeat does not mutate');
check(ReportAdminState::$audit===1,'repeat does not create bogus audit');
ReportAdminRequest::$desired='open';
$result=runReportAction($controller,'PATCH');
check($result['status']===200,'reopen accepted');
check(ReportAdminState::$reports[42]['status']==='open','reopen saved');
check(ReportAdminState::$reports[42]['reviewed_by']===null,'reopen clears reviewer');
check(ReportAdminState::$audit===2,'reopen audited once');
ReportAdminRequest::$desired='resolved';
ReportAdminState::reset();ReportAdminState::$users[1]['role']='member';
$result=runReportAction($controller,'GET');
check($result['status']===403,'stale admin cannot read reported PM after demotion');
check(!isset($result['body']) || !str_contains(json_encode($result['body']),'SECRET'), 'PM body not disclosed');
ReportAdminState::reset();ReportAdminState::$users[1]['is_banned']=true;
$result=runReportAction($controller,'GET');
check($result['status']===403,'banned admin cannot read reported PM');
ReportAdminState::reset();ReportAdminState::$beforePage=static function () {ReportAdminState::$users[1]['role']='member';};
$result=runReportAction($controller,'GET');
check($result['status']===403,'role revoked during pagination cannot read report');
check(!isset($result['body']) || !str_contains(json_encode($result['body']),'SECRET'),'page contains no secret');
ReportAdminState::reset();$result=runReportAction($controller,'GET');
check($result['status']===200,'active admin can view reported PM');
check(($result['body']['data'][0]->body ?? '')==='SECRET PRIVATE PM','reported message visible only to admin');
print "PASS: {$checks} real controller fake DB assertions (NOT HTTP/PDO)\n";
