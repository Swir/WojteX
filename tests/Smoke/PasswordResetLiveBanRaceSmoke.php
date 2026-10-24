<?php
/**
 * Calls the ACTUAL PasswordRecoveryController::reset with a deterministic
 * token-broker seam and a mutable live users table. The broker triggers a ban
 * AFTER the controller's preliminary check but BEFORE the password write.
 * Uses fake DB/model/broker: NOT HTTP, PDO, SMTP or physical concurrency.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\PasswordRecoveryController;
use Illuminate\Container\Container;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class PasswordResetRaceState {
    public static bool $exists = true;
    public static bool $banned = false;
    public static bool $preBanned = false;
    public static string $race = '';
    public static string $password = 'hash-before';
    public static ?string $legacyHash = 'legacy-hash';
    public static ?string $legacySalt = 'legacy-salt';
    public static string $rememberToken = 'remember-before';
    public static int $writes = 0;
    public static int $attempts = 0;
    public static int $brokerCalls = 0;
    public static array $filters = [];
}
final class PasswordResetStaleUser {
    public int $id = 7;
    public bool $is_banned;
    public function __construct() { $this->is_banned = PasswordResetRaceState::$preBanned; }
    public static function where(string $key, mixed $value): PasswordResetLookup {
        if ($key !== 'email' || $value !== 'person@example.test') throw new RuntimeException('unexpected account lookup');
        return new PasswordResetLookup();
    }
    public function getKey(): int {return $this->id;}
    public function forceFill(array $values): static {
        // This is the OLD unguarded Eloquent model write path. A cached
        // User::save() cannot enforce users.is_banned at UPDATE time.
        foreach ($values as $key => $value) $this->{$key} = $value;
        return $this;
    }
    public function save(): bool {
        ++PasswordResetRaceState::$attempts;
        ++PasswordResetRaceState::$writes;
        PasswordResetRaceState::$password = $this->password;
        PasswordResetRaceState::$legacyHash = $this->legacy_password_hash;
        PasswordResetRaceState::$legacySalt = $this->legacy_password_salt;
        PasswordResetRaceState::$rememberToken = $this->remember_token;
        return true;
    }
}
class_alias(PasswordResetStaleUser::class, 'App\\Models\\User');
final class PasswordResetLookup {
    public function first(): ?PasswordResetStaleUser {return PasswordResetRaceState::$exists ? new PasswordResetStaleUser() : null;}
}
final class PasswordResetFakeRequest extends Request {
    public function validate(array $rules, ...$params): array {
        return [
            'email'=>'person@example.test', 'token'=>str_repeat('X',64),
            'password'=>'ReplacementPassword2026!', 'password_confirmation'=>'ReplacementPassword2026!',
        ];
    }
}
final class PasswordResetFakeBroker {
    public function reset(array $credentials, callable $callback): string {
        ++PasswordResetRaceState::$brokerCalls;
        if (PasswordResetRaceState::$race === 'ban') PasswordResetRaceState::$banned = true;
        if (PasswordResetRaceState::$race === 'delete') PasswordResetRaceState::$exists = false;
        $callback(new PasswordResetStaleUser(), $credentials['password']);
        return Password::PASSWORD_RESET;
    }
}
final class PasswordResetFakeManager {
    public function broker(): PasswordResetFakeBroker { return new PasswordResetFakeBroker(); }
}
final class PasswordResetFakeHash {
    public function make(string $password): string {return password_hash($password, PASSWORD_BCRYPT, ['cost'=>4]);}
}
final class PasswordResetFakeDB {
    public function table(string $table): PasswordResetFakeQuery {
        if ($table !== 'users') throw new RuntimeException('unexpected SQL table '.$table);
        return new PasswordResetFakeQuery();
    }
}
final class PasswordResetFakeQuery {
    private array $filters = [];
    public function where(string $field,mixed $value): static { $this->filters[$field] = $value; return $this; }
    public function update(array $values): int {
        ++PasswordResetRaceState::$attempts;
        if ($this->filters !== ['id'=>7, 'is_banned'=>false])
            throw new RuntimeException('reset UPDATE lacks authoritative id and live ban predicates: '.json_encode($this->filters));
        PasswordResetRaceState::$filters = $this->filters;
        if (!PasswordResetRaceState::$exists || PasswordResetRaceState::$banned) return 0;
        if (array_keys($values) !== ['password','legacy_password_hash','legacy_password_salt','remember_token','updated_at'])
            throw new RuntimeException('unexpected reset UPDATE fields');
        if (!password_verify('ReplacementPassword2026!', $values['password']) ||
            $values['legacy_password_hash'] !== null || $values['legacy_password_salt'] !== null ||
            $values['remember_token'] === PasswordResetRaceState::$rememberToken)
            throw new RuntimeException('password hash/legacy cleanup/remember rotation failed');
        ++PasswordResetRaceState::$writes;
        PasswordResetRaceState::$password = $values['password'];
        PasswordResetRaceState::$legacyHash = $values['legacy_password_hash'];
        PasswordResetRaceState::$legacySalt = $values['legacy_password_salt'];
        PasswordResetRaceState::$rememberToken = $values['remember_token'];
        return 1;
    }
}

$app = new class extends Container {
    public function abort(int $code, string $message = '', array $headers = []): never {
        throw new HttpException($code, $message, null, $headers);
    }
};
Container::setInstance($app); Facade::setFacadeApplication($app);
$app->instance('translator', new class { public function get(string $key): string {return $key;} });
$app->instance(\Illuminate\Contracts\Routing\ResponseFactory::class,
    new class extends \Illuminate\Routing\ResponseFactory {
        public function __construct() {}
        public function json($data=[], $status=200, array $headers=[], $options=0): JsonResponse {
            return new JsonResponse($data, $status, $headers, $options);
        }
    });
DB::swap(new PasswordResetFakeDB()); Password::swap(new PasswordResetFakeManager()); Hash::swap(new PasswordResetFakeHash());
$controller = new PasswordRecoveryController(); $checks = 0;
foreach ([
    ['ban between preflight and token callback', false, 'ban', 422, 0, 1],
    ['deletion between preflight and token callback', false, 'delete', 422, 0, 1],
    ['already banned on first read', true, '', 422, 0, 0],
    ['active user token succeeds', false, '', 200, 1, 1],
] as [$label,$preBanned,$race,$expectedStatus,$expectedWrites,$expectedBrokerCalls]) {
    PasswordResetRaceState::$exists=true;
    PasswordResetRaceState::$preBanned=$preBanned;
    PasswordResetRaceState::$banned=$preBanned;
    PasswordResetRaceState::$race=$race;
    PasswordResetRaceState::$password='hash-before';
    PasswordResetRaceState::$legacyHash='legacy-hash';
    PasswordResetRaceState::$legacySalt='legacy-salt';
    PasswordResetRaceState::$rememberToken='remember-before';
    PasswordResetRaceState::$writes=PasswordResetRaceState::$attempts=PasswordResetRaceState::$brokerCalls=0;
    PasswordResetRaceState::$filters=[];
    $request=PasswordResetFakeRequest::create('/api/auth/password/reset','POST');
    try { $actual=$controller->reset($request)->getStatusCode(); }
    catch(HttpExceptionInterface $error) {$actual=$error->getStatusCode();}
    if ($actual!==$expectedStatus || PasswordResetRaceState::$writes!==$expectedWrites) {
        fwrite(STDERR, "RED: $label expected HTTP $expectedStatus / writes $expectedWrites, got HTTP $actual / writes ".PasswordResetRaceState::$writes."\n");
        exit(1);
    }
    $checks+=2;
    if (PasswordResetRaceState::$brokerCalls!==$expectedBrokerCalls) {fwrite(STDERR,"wrong broker invocations $label\n");exit(1);}
    ++$checks;
    if ((PasswordResetRaceState::$password === 'hash-before') !== ($expectedWrites === 0)) {fwrite(STDERR,"password preservation mismatch $label\n");exit(1);}
    ++$checks;
    if ($expectedWrites===0 && (PasswordResetRaceState::$legacyHash!=='legacy-hash' || PasswordResetRaceState::$rememberToken!=='remember-before')) {fwrite(STDERR,"denied reset mutated user $label\n");exit(1);}
    if ($expectedWrites===1 && (!password_verify('ReplacementPassword2026!',PasswordResetRaceState::$password) || PasswordResetRaceState::$legacyHash!==null || PasswordResetRaceState::$legacySalt!==null || PasswordResetRaceState::$rememberToken==='remember-before')) {
        fwrite(STDERR,"active reset did not rotate all credentials\n");exit(1);
    }
    $checks+=3;
}
echo "PASS: $checks PasswordRecoveryController live-ban/delete race checks (FAKE DB/BROKER, not HTTP/PDO/SMTP)\n";
