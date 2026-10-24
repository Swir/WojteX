<?php
/**
 * Exercise the production CommunityController::shout with a fake DB whose
 * durable ban state changes AFTER the authenticated user object was loaded.
 * Old insertGetId creates a shout despite that ban. A guarded INSERT..SELECT
 * must refuse it, even with the same stale session user object.
 * This is NOT a PHP PDO, concurrent database, HTTP, or CSRF test.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\CommunityController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ShoutAtomicAccepted extends RuntimeException {}
final class ShoutAtomicDatabase {
    public bool $enabled = true;
    public bool $accountExists = true;
    public bool $dbBanned = false;
    public int $writes = 0;
    public function table(string $name): ShoutAtomicQuery { return new ShoutAtomicQuery($this, $name); }
}
final class ShoutAtomicQuery {
    private array $predicates = [];
    private bool $hasSelection = false;
    private bool $livePluginGuard = false;
    public function from(string $source): self {
        if ($this->table !== 'subquery' || $source !== 'plugin_states as live_shoutbox') throw new RuntimeException('Unexpected plugin subquery');
        $this->livePluginGuard = true;
        return $this;
    }
    public function whereExists(callable $callback): self {
        $sub = new self($this->database, 'subquery');
        $callback($sub);
        if (!$sub->livePluginGuard) throw new RuntimeException('Missing live plugin gate');
        $this->livePluginGuard = true;
        return $this;
    }
    public function __construct(private ShoutAtomicDatabase $database, public readonly string $table) {}
    public function where(string $column, mixed $operator, mixed $value = null): self {
        $this->predicates[$column] = $value === null ? $operator : $value;
        return $this;
    }
    public function value(string $column): bool {
        if ($this->table !== 'plugin_states' || $column !== 'enabled') throw new RuntimeException('Unexpected plugin read');
        return $this->database->enabled;
    }
    public function selectRaw(string $sql, array $bindings = []): self {
        if ($this->table === 'subquery') return $this;
        if ($this->table !== 'users' || !str_contains($sql, 'AS user_id') || count($bindings) !== 4) throw new RuntimeException('Unexpected SELECT');
        $this->hasSelection = true;
        return $this;
    }
    public function insertGetId(array $values): never {
        if ($this->table !== 'shouts') throw new RuntimeException('Unexpected INSERT target');
        $this->database->writes++;
        throw new ShoutAtomicAccepted('Unconditional INSERT executed');
    }
    public function insertUsing(array $columns, mixed $query): int {
        if ($this->table !== 'shouts' || $columns !== ['user_id','body','created_at','updated_at']
            || !$query instanceof self || $query->table !== 'users' || !$query->hasSelection
            || !array_key_exists('users.id', $query->predicates)
            || !array_key_exists('users.is_banned', $query->predicates)
            || $query->predicates['users.is_banned'] !== false || !$query->livePluginGuard) {
            throw new RuntimeException('Missing guarded INSERT..SELECT');
        }
        if ($this->database->accountExists && !$this->database->dbBanned) {
            $this->database->writes++;
            throw new ShoutAtomicAccepted('Guarded INSERT accepted');
        }
        return 0;
    }
}
final class ShoutAtomicRequest extends Request {
    public function validate(array $rules): array {return ['body'=>$this->input('body')];}
}
$app = new class extends Container {
    public function abort(int $code, string $message='', array $headers=[]): never {throw new HttpException($code,$message,null,$headers);}
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$translator = new class {
    public string $locale = 'en';
    public function get(string $key): string {
        $catalog = json_decode(file_get_contents(dirname(__DIR__, 2).'/lang/'.$this->locale.'/server.json'), true, 64, JSON_THROW_ON_ERROR);
        return $catalog[$key] ?? throw new RuntimeException('Missing '.$key);
    }
};
$app->instance('translator', $translator);
$db = new ShoutAtomicDatabase();
DB::swap($db);
$controller = new CommunityController();
$checks = 0;
foreach ([
    [false, true, true, true, 'pl', 403, 'stale logged-in user banned before insertion'],
    [false, true, true, true, 'en', 403, 'same race; English message'],
    [false, false, true, true, 'en', 'insert', 'active account may shout'],
    [true, false, true, true, 'pl', 403, 'cached banned user cannot shout'],
    [false, false, false, true, 'en', 403, 'account deleted before insertion'],
    [false, false, true, false, 'en', 404, 'disabled module cannot insert'],
] as [$cachedBan, $dbBan, $exists, $enabled, $locale, $expected, $label]) {
    $db->dbBanned = $dbBan; $db->accountExists = $exists; $db->enabled = $enabled; $db->writes = 0;
    $translator->locale = $locale;
    $request = ShoutAtomicRequest::create('/api/shouts', 'POST', ['body'=>'Cached-session post']);
    $request->setUserResolver(static fn () => (object)['id'=>23,'is_banned'=>$cachedBan]);
    $actual = null; $message = '';
    try {$controller->shout($request);}
    catch (ShoutAtomicAccepted) {$actual='insert';}
    catch (HttpExceptionInterface $e) {$actual=$e->getStatusCode();$message=$e->getMessage();}
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$label}; expected ".json_encode($expected).", got ".json_encode($actual).", writes={$db->writes}\n");exit(1);
    }
    $checks++;
    if ($db->writes !== ($expected === 'insert' ? 1 : 0)) throw new RuntimeException('Unexpected attempted write: '.$label);
    $checks++;
    if ($expected === 403 && $message !== $translator->get('server.account_blocked')) throw new RuntimeException('Bad PL/EN denied message: '.$label);
    if ($expected === 403) $checks++;
}
echo "PASS: {$checks} real CommunityController stale-session ban checks (fake DB; not PDO/HTTP)\n";
