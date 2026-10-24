<?php
/**
 * Exercises the ACTUAL CommunityController::shout action with a minimal database
 * seam. No database or HTTP runtime is available here; this cannot prove routing,
 * CSRF or SQL, and full Feature/ForumSecurityTest remains the HTTP gate.
 *
 * The fake shout insert deliberately throws. A banned account must abort with
 * 403 BEFORE the insert; an active account must reach that same insertion point.
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\CommunityController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class AttemptedShoutInsert extends RuntimeException {}

final class ShoutSmokeQuery
{
    public function __construct(private readonly bool $enabled, private readonly string $table) {}
    public function where(string $field, mixed $value): self { return $this; }
    public function value(string $field): bool { return $this->enabled; }
    private array $guards = [];
    private bool $selected = false;
    private bool $livePluginGuard = false;
    public function from(string $source): self {
        if ($this->table !== 'subquery' || $source !== 'plugin_states as live_shoutbox') throw new RuntimeException('Unexpected plugin subquery');
        $this->livePluginGuard = true;
        return $this;
    }
    public function whereExists(callable $callback): self {
        $sub = new self($this->enabled, 'subquery');
        $callback($sub);
        if (!$sub->livePluginGuard) throw new RuntimeException('Missing live plugin gate');
        $this->livePluginGuard = true;
        return $this;
    }
    public function selectRaw(string $sql, array $bindings=[]): self {
        if ($this->table === 'subquery') return $this;
        if ($this->table !== 'users' || !str_contains($sql, 'AS user_id') || count($bindings) !== 4) throw new RuntimeException('Invalid eligibility SELECT');
        $this->selected = true;
        return $this;
    }
    public function insertGetId(array $row): never { throw new RuntimeException('Unconditional INSERT is forbidden'); }
    public function insertUsing(array $columns, mixed $select): never {
        if ($this->table !== 'shouts' || $columns !== ['user_id','body','created_at','updated_at']
            || !$select instanceof self || $select->table !== 'users' || !$select->selected || !$select->livePluginGuard) {
            throw new RuntimeException('Missing eligibility guard');
        }
        throw new AttemptedShoutInsert('Guarded shout insert reached');
    }
}
final class ShoutSmokeDatabase
{
    public function __construct(public bool $enabled = true) {}
    public function table(string $table): ShoutSmokeQuery { return new ShoutSmokeQuery($this->enabled, $table); }
}
final class ShoutSmokeRequest extends Request
{
    public function validate(array $rules): array { return ['body' => $this->input('body')]; }
}

$app = new class extends Container {
    public function abort(int $code, string $message = '', array $headers = []): never {
        throw new HttpException($code, $message, null, $headers);
    }
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
// Resolve the real PL/EN key from project catalogs (no Laravel bootstrap).
$translator = new class {
    public string $locale = 'en';
    public function get(string $key): string {
        $catalog = json_decode(file_get_contents(dirname(__DIR__, 2).'/lang/'.$this->locale.'/server.json'), true, 64, JSON_THROW_ON_ERROR);
        return $catalog[$key] ?? throw new RuntimeException('Missing translation: '.$this->locale.'/'.$key);
    }
};
$app->instance('translator', $translator);
$database = new ShoutSmokeDatabase();
DB::swap($database);
$controller = new CommunityController();
$checks = 0;

foreach ([
    [true, true, 'pl', 403, 'zablokowany użytkownik, komunikat PL'],
    [true, true, 'en', 403, 'blocked user, English message'],
    [false, true, 'pl', 'insert', 'active PL account reaches shout insert'],
    [false, true, 'en', 'insert', 'active EN account reaches shout insert'],
    [true, false, 'pl', 404, 'disabled shoutbox unavailable to blocked user'],
    [false, false, 'en', 404, 'disabled shoutbox unavailable to active user'],
] as [$banned, $enabled, $locale, $expected, $label]) {
    $database->enabled = $enabled;
    $translator->locale = $locale;
    $request = ShoutSmokeRequest::create('/api/shouts', 'POST', ['body' => 'Smoke content']);
    $request->setUserResolver(static fn () => (object) ['id' => 37, 'is_banned' => $banned]);
    $actual = 'unexpected';
    $message = '';
    try {
        $controller->shout($request);
    } catch (AttemptedShoutInsert) {
        $actual = 'insert';
    } catch (HttpExceptionInterface $e) {
        $actual = $e->getStatusCode();
        $message = $e->getMessage();
    }
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$label}; expected {$expected}, actual {$actual}\n");
        exit(1);
    }
    $checks++;
    if ($banned && $enabled) {
        $expectedMessage = $translator->get('server.account_blocked');
        if ($message !== $expectedMessage) {
            fwrite(STDERR, "FAIL: {$label}; unlocalized ban message\n");
            exit(1);
        }
        $checks++;
    }
}

echo "PASS: {$checks} actual CommunityController shout access checks (fake DB, no HTTP)\n";
