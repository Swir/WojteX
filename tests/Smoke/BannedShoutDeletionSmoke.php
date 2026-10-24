<?php
/**
 * Executes the real CommunityController::deleteShout on a fake DB seam.
 * A banned existing session must be rejected before the DELETE, including
 * when it owns the shout. This does not qualify PDO/HTTP/CSRF.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\CommunityController;
use App\Models\User;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class ShoutDeletionAttempted extends RuntimeException {}
final class DeleteSmokeDatabase
{
    public bool $enabled = true;
    public int $ownerId = 37;
    public int $deletionAttempts = 0;
    public function table(string $name): DeleteSmokeQuery {return new DeleteSmokeQuery($this, $name);}
}
final class DeleteSmokeQuery
{
    public function __construct(private DeleteSmokeDatabase $database, private string $name) {}
    public function where(mixed $field, mixed $value = null): self {
        if (is_callable($field)) $field($this);
        return $this;
    }
    public function whereExists(callable $cb): self {
        $cb(new self($this->database, 'subquery'));
        return $this;
    }
    public function orWhereExists(callable $cb): self {
        $cb(new self($this->database, 'subquery'));
        return $this;
    }
    public function from(string $name): self {return $this;}
    public function selectRaw(string $sql): self {return $this;}
    public function whereIn(string $column, array $values): self {return $this;}
    public function value(string $field): mixed
    {
        if ($this->name !== 'plugin_states') throw new RuntimeException('Wrong table for plugin');
        return $this->database->enabled;
    }
    public function find(int $id): object
    {
        if ($this->name !== 'shouts') throw new RuntimeException('Wrong table for lookup');
        return (object)['id'=>$id,'user_id'=>$this->database->ownerId];
    }
    public function delete(): never
    {
        if ($this->name !== 'shouts') throw new RuntimeException('Wrong table for deletion');
        $this->database->deletionAttempts++;
        throw new ShoutDeletionAttempted('DELETE reached');
    }
}
$app = new class extends Container {
    public function abort(int $code, string $message = '', array $headers = []): never
    {throw new HttpException($code, $message, null, $headers);}
};
Container::setInstance($app);
Facade::setFacadeApplication($app);
$translator = new class {
    public string $locale = 'en';
    public function get(string $key): string
    {
        $catalog=json_decode(file_get_contents(dirname(__DIR__, 2).'/lang/'.$this->locale.'/server.json'), true, 64, JSON_THROW_ON_ERROR);
        return $catalog[$key] ?? throw new RuntimeException('Missing translation '.$this->locale.'/'.$key);
    }
};
$app->instance('translator', $translator);
$database=new DeleteSmokeDatabase();
DB::swap($database);
$controller=new CommunityController();
$checks=0;
foreach ([
    [true, true, 37, 'pl', 403],
    [true, true, 37, 'en', 403],
    [false, true, 37, 'pl', 'delete'],
    [false, true, 37, 'en', 'delete'],
    [false, true, 64, 'en', 403],
    [true, false, 37, 'pl', 404],
    [false, false, 37, 'en', 404],
] as [$banned, $enabled, $owner, $locale, $expected]) {
    $database->enabled=$enabled;
    $database->ownerId=$owner;
    $database->deletionAttempts=0;
    $translator->locale=$locale;
    $request=Request::create('/api/shouts/88', 'DELETE');
    $account=new User(); $account->id=37; $account->is_banned=$banned;
    $request->setUserResolver(static fn () => $account);
    $actual='unexpected'; $reason='';
    try {$controller->deleteShout($request,88);}
    catch (ShoutDeletionAttempted) {$actual='delete';}
    catch (HttpExceptionInterface $e) {$actual=$e->getStatusCode();$reason=$e->getMessage();}
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL locale={$locale} banned=".(int)$banned." enabled=".(int)$enabled." owner={$owner}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
    ++$checks;
    $expectDelete=($expected==='delete')?1:0;
    if ($database->deletionAttempts!==$expectDelete) {
        fwrite(STDERR,"FAIL unexpected DELETE attempts: {$database->deletionAttempts}\n"); exit(1);
    }
    ++$checks;
    if ($banned && $enabled && $reason!==$translator->get('server.account_blocked')) {
        fwrite(STDERR,"FAIL ban response not localized: {$locale}\n");exit(1);
    }
    if ($banned && $enabled)++$checks;
}
echo "PASS {$checks} actual CommunityController deleteShout policy assertions (fake DB, no PDO/HTTP)\n";
