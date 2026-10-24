<?php
/**
 * Run the real AuthController::register with deterministic race injection.
 * The registration gate closes AFTER the cached settings check and BEFORE the
 * final user write. Fake database and session; NOT HTTP/PDO/concurrency proof.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\AuthController;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $s): string { return strtolower($s); } }
final class RegistrationRaceState {
    public static bool $preflightOpen = true;
    public static ?string $liveFlag = 'true';
    public static bool $installed = true;
    public static bool $closeDuringHash = false;
    public static int $writes = 0;
    public static int $attempts = 0;
    public static int $logins = 0;
    public static int $events = 0;
    public static bool $guarded = false;
    public static ?RegistrationFakeUser $saved = null;
}
final class RegistrationFakeForum {
    public const COLORS = ['violet','cyan','amber','rose','green','blue'];
    public static function settings(): array { return ['registration_open'=>RegistrationRaceState::$preflightOpen]; }
    public static function selfUser(object $user): array { return ['id'=>$user->id,'name'=>$user->name,'email'=>$user->email]; }
}
class_alias(RegistrationFakeForum::class, 'App\\Services\\ForumService');
final class RegistrationFakeUser {
    public int $id = 19;
    public string $name='';
    public string $email='';
    private string $encodedPassword='';
    public string $role='member';
    public string $color='violet';
    public function __set(string $key, mixed $value): void {
        if ($key === 'password') {
            $this->encodedPassword = 'already-hashed-test-password';
            if (RegistrationRaceState::$closeDuringHash) RegistrationRaceState::$liveFlag = 'false';
            return;
        }
        $this->{$key} = $value;
    }
    public function __get(string $key): mixed {
        if ($key === 'password') return $this->encodedPassword;
        throw new RuntimeException('Unknown dynamic property '.$key);
    }
    public function save(): bool {
        ++RegistrationRaceState::$writes;
        RegistrationRaceState::$saved = $this;
        return true;
    }
    public static function findOrFail(int $id): self {
        if ($id !== 19 || !RegistrationRaceState::$saved) throw new RuntimeException('Unable to resolve inserted user');
        return RegistrationRaceState::$saved;
    }
}
class_alias(RegistrationFakeUser::class, 'App\\Models\\User');
final class RegistrationFakeQuery {
    public array $where=[];
    public ?RegistrationFakeQuery $notExists=null;
    public ?string $sql=null;
    public array $bindings=[];
    public function __construct(public string $table) {}
    public function from(string $name): self {$this->table=$name; return $this;}
    public function where(string $column, mixed $operator, mixed $value=null): self {
        $this->where[$column] = func_num_args()===2 ? ['=', $operator] : [$operator, $value];
        return $this;
    }
    public function whereNotExists(callable $callback): self {
        $this->notExists = new self('');
        $callback($this->notExists);
        return $this;
    }
    public function selectRaw(string $sql,array $bindings=[]): self {$this->sql=$sql;$this->bindings=$bindings;return $this;}
    public function insertUsing(array $columns, self $eligible): int {
        if ($this->table !== 'users' || $eligible->table !== 'settings'
            || $eligible->where['key'] !== ['=','setup_complete']
            || $eligible->where['value'] !== ['=','true']
            || !$eligible->notExists || $eligible->notExists->table !== 'settings as registration_flag'
            || $eligible->notExists->where['registration_flag.key'] !== ['=','registration_open']
            || $eligible->notExists->where['registration_flag.value'] !== ['<>','true']
            || count($eligible->bindings) !== count($columns)
            || $columns !== ['name','email','password','role','color','created_at','updated_at']) {
            throw new RuntimeException('Expected statement-scoped registration gate, got unguarded insert');
        }
        ++RegistrationRaceState::$attempts;
        RegistrationRaceState::$guarded = true;
        if (!RegistrationRaceState::$installed || (RegistrationRaceState::$liveFlag !== null && RegistrationRaceState::$liveFlag !== 'true')) return 0;
        ++RegistrationRaceState::$writes;
        $user = new RegistrationFakeUser();
        $user->name = (string) $eligible->bindings[0];
        $user->email = (string) $eligible->bindings[1];
        // Already hashed by the model before it enters the query.
        $user->password = (string) $eligible->bindings[2];
        RegistrationRaceState::$saved = $user;
        return 1;
    }
}
final class RegistrationFakeDB {
    public function table(string $name): RegistrationFakeQuery {return new RegistrationFakeQuery($name);}
    public function connection(): object {return new class {
        public function getPdo(): object {return new class {public function lastInsertId(): string {return '19';}};}
    };}
}
final class RegistrationFakeAuth {
    public function login(object $user): void { ++RegistrationRaceState::$logins; }
}
final class RegistrationFakeEvent {
    public function dispatch(string $name,array $data): void {
        if ($name !== 'veyra.user.registered' || $data !== [19]) throw new RuntimeException('Unexpected event');
        ++RegistrationRaceState::$events;
    }
}
final class RegistrationFakeRequest extends Request {
    public function validate(array $rules, ...$params): array {
        return ['name'=>'Registered','email'=>'member@example.test','password'=>'some-secure-password-2026'];
    }
}
$app = new class extends Container {
    public function abort(int $status,string $message='',array $headers=[]): never {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message,null,$headers);
    }
};
Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string {return $key;}});
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json($data=[],$status=200,array $headers=[],$options=0): JsonResponse {
            return new JsonResponse($data,$status,$headers,$options);
        }
    });
DB::swap(new RegistrationFakeDB()); Auth::swap(new RegistrationFakeAuth()); Event::swap(new RegistrationFakeEvent());
$checks=0;
foreach ([
    ['registration closed while password is hashed', true,'true',true,true,403,0],
    ['registration open until write', true,'true',true,false,201,1],
    ['registration flag absent means installed default open', true,null,true,false,201,1],
    ['registration already closed',false,'false',true,false,403,0],
    ['invalid stored registration flag fails closed',true,'null',true,false,403,0],
    ['installation not complete',true,'true',false,false,403,0],
] as [$label,$preflight,$flag,$installed,$race,$expectedStatus,$expectedWrites]) {
    RegistrationRaceState::$preflightOpen=$preflight;
    RegistrationRaceState::$liveFlag=$flag;
    RegistrationRaceState::$installed=$installed;
    RegistrationRaceState::$closeDuringHash=$race;
    RegistrationRaceState::$writes=RegistrationRaceState::$attempts=RegistrationRaceState::$logins=RegistrationRaceState::$events=0;
    RegistrationRaceState::$guarded=false;RegistrationRaceState::$saved=null;
    $request=RegistrationFakeRequest::create('/api/auth/register','POST');
    $session=new Store('registration-smoke',new ArraySessionHandler(60));$session->start();$request->setLaravelSession($session);
    try {$actual=(new AuthController())->register($request)->getStatusCode();}
    catch (HttpExceptionInterface $exception) {$actual=$exception->getStatusCode();}
    if ($actual!==$expectedStatus || RegistrationRaceState::$writes!==$expectedWrites) {
        fwrite(STDERR,"RED: $label expected status $expectedStatus and writes $expectedWrites, actual ".json_encode($actual).
            ' and writes '.RegistrationRaceState::$writes."\n");exit(1);
    }
    $checks+=2;
    if ($preflight && (!RegistrationRaceState::$guarded || RegistrationRaceState::$attempts!==1)) {
        fwrite(STDERR,"FAIL: $label lacked final guarded statement\n");exit(1);
    }
    ++$checks;
    if (RegistrationRaceState::$logins!==$expectedWrites || RegistrationRaceState::$events!==$expectedWrites) {
        fwrite(STDERR,"FAIL: $label authorized event or session incorrectly\n");exit(1);
    }
    $checks+=2;
}
echo "PASS: $checks registration close/reopen/malformed/install checks on real PHP controller + fake DB (NOT PDO/HTTP)\n";
