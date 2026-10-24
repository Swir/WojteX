<?php
/**
 * Actual AuthController::login, deterministic credential/ban update AFTER the
 * first Hash::check yet BEFORE the final login. Uses fake User/DB/Auth/Hash.
 * This is NOT Laravel HTTP, PDO or physical concurrency.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\AuthController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Session\Store;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Factory as ValidationFactory;
use Illuminate\Translation\Translator;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Validation\ValidationException;

if (!function_exists('mb_strtolower')) {function mb_strtolower(string $v): string{return strtolower($v);}}
final class LoginRaceState {
    public static bool $exists=true;
    public static bool $banned=false;
    public static string $password='hash-old';
    public static string $role='member';
    public static string $race='';
    public static int $logins=0;
    public static int $saves=0;
    public static int $locks=0;
    public static int $transactions=0;
    public static bool $inTransaction=false;
    public static ?object $loggedUser=null;
}
final class LoginFakeUser {
    public int $id=7;
    public string $email='member@example.test';
    public string $password;
    public bool $is_banned;
    public string $role;
    public ?string $legacy_password_hash=null;
    public ?string $legacy_password_salt=null;
    public function __construct() {
        $this->password=LoginRaceState::$password;
        $this->is_banned=LoginRaceState::$banned;
        $this->role=LoginRaceState::$role;
    }
    public static function where(string $column, mixed $value): LoginFakeUserQuery {
        if ($column!=='email'||$value!=='member@example.test') throw new RuntimeException('unexpected login lookup');
        return new LoginFakeUserQuery(false);
    }
    public static function whereKey(int $key): LoginFakeUserQuery {
        if($key!==7) throw new RuntimeException('unexpected account id');
        return new LoginFakeUserQuery(true);
    }
    public function getKey(): int {return $this->id;}
    public function save(): bool {
        ++LoginRaceState::$saves;
        if (!LoginRaceState::$inTransaction) throw new RuntimeException('credential upgrade must be transactional');
        LoginRaceState::$password=$this->password;
        return true;
    }
}
class_alias(LoginFakeUser::class,'App\\Models\\User');
final class LoginFakeUserQuery {
    private bool $locked=false;
    public function __construct(private bool $live) {}
    public function lockForUpdate(): static {
        if (!$this->live || !LoginRaceState::$inTransaction) throw new RuntimeException('lock outside final transaction');
        ++LoginRaceState::$locks;
        $this->locked=true;
        return $this;
    }
    public function first(): ?LoginFakeUser {
        if ($this->live && !$this->locked) throw new RuntimeException('live account read without lock');
        return LoginRaceState::$exists ? new LoginFakeUser() : null;
    }
}
final class LoginFakeDB {
    public function transaction(callable $callback): mixed {
        ++LoginRaceState::$transactions;
        if (LoginRaceState::$inTransaction) throw new RuntimeException('nested transaction');
        LoginRaceState::$inTransaction=true;
        try {return $callback();}
        finally {LoginRaceState::$inTransaction=false;}
    }
}
final class LoginFakeHash {
    public function check(string $password,string $hash): bool {
        $matches=$password==='SafeForumPassword2026!'&&$hash==='hash-old';
        if (LoginRaceState::$race==='ban') LoginRaceState::$banned=true;
        if (LoginRaceState::$race==='delete') LoginRaceState::$exists=false;
        if (LoginRaceState::$race==='reset') LoginRaceState::$password='hash-new-after-reset';
        if (LoginRaceState::$race==='promote') LoginRaceState::$role='admin';
        LoginRaceState::$race='';
        return $matches;
    }
    public function needsRehash(string $hash): bool {return false;}
    public function make(string $password): string {return 'hash-dummy';}
}
final class LoginFakeAuth {
    public function login(object $user): void {
        ++LoginRaceState::$logins;
        LoginRaceState::$loggedUser=$user;
    }
}
final class LoginFakeForum {
    public static function selfUser(object $user): array {
        return ['id'=>$user->id,'email'=>$user->email,'role'=>$user->role];
    }
}
class_alias(LoginFakeForum::class,'App\\Services\\ForumService');
final class LoginFakeRequest extends Request {
    public function validate(array $rules, ...$params): array {
        return ['email'=>'member@example.test','password'=>'SafeForumPassword2026!'];
    }
}
$app=new Container();Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator', new class {public function get(string $key): string {return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json($data=[], $status=200, array $headers=[], $options=0): JsonResponse {
            return new JsonResponse($data,$status,$headers,$options);
        }
    });
DB::swap(new LoginFakeDB());Auth::swap(new LoginFakeAuth());Hash::swap(new LoginFakeHash());
Validator::swap(new ValidationFactory(new Translator(new ArrayLoader(),'en')));
$controller=new AuthController();$checks=0;
foreach([
    ['ban after password check','ban',422,0,1],
    ['deleted after password check','delete',422,0,1],
    ['reset after password check','reset',422,0,1],
    ['valid account', '',200,1,1],
    ['promoted while checking password','promote',200,1,1],
] as [$label,$race,$expectedStatus,$expectedLogins,$expectedLocks]) {
    LoginRaceState::$exists=true;LoginRaceState::$banned=false;
    LoginRaceState::$password='hash-old';LoginRaceState::$role='member';
    LoginRaceState::$race=$race;LoginRaceState::$logins=LoginRaceState::$saves=0;
    LoginRaceState::$locks=LoginRaceState::$transactions=0;
    LoginRaceState::$inTransaction=false;LoginRaceState::$loggedUser=null;
    $request=LoginFakeRequest::create('/api/auth/login','POST');
    $session=new Store('veyra-smoke',new ArraySessionHandler(120));$session->start();
    $request->setLaravelSession($session);
    try {$actual=$controller->login($request)->getStatusCode();}
    catch(ValidationException $e) {$actual=$e->status;}
    if ($actual!==$expectedStatus || LoginRaceState::$logins!==$expectedLogins || LoginRaceState::$saves!==0) {
        fwrite(STDERR,"RED: $label expected HTTP $expectedStatus / logins $expectedLogins / saves 0; got $actual / ".LoginRaceState::$logins." / ".LoginRaceState::$saves."\n"); exit(1);
    }
    $checks+=3;
    if (LoginRaceState::$locks!==$expectedLocks || LoginRaceState::$transactions!==1 || LoginRaceState::$inTransaction) {
        fwrite(STDERR,"RED: $label missing final locked transaction (locks ".LoginRaceState::$locks.", transactions ".LoginRaceState::$transactions.")\n");exit(1);
    }
    $checks+=3;
    if ($expectedLogins && LoginRaceState::$loggedUser->role!==LoginRaceState::$role) {
        fwrite(STDERR,"RED: $label response retains stale role\n");exit(1);
    }
    ++$checks;
}
echo "PASS: $checks AuthController login credential-revocation race checks (FAKE DB/Auth/Hash, NOT PDO/HTTP)\n";
