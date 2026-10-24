<?php
/**
 * Invoke the real InstallController::store against a synthetic occupied/empty
 * schema. Artisan is a fake migration facade: it aborts at migration entry.
 * The occupied schema must be rejected BEFORE Artisan::call('migrate').
 * This is not an HTTP/PDO/migration execution or concurrency test.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Controllers\InstallController;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class InstallerGateState {
    public static bool $installed=false;
    public static bool $usersTable=true;
    public static bool $occupied=true;
    public static bool $validToken=true;
    public static int $schemaChecks=0;
    public static int $userQueries=0;
    public static int $migrations=0;
}
final class InstallerGateForum {
    public static function installed(): bool {return InstallerGateState::$installed;}
}
class_alias(InstallerGateForum::class, 'App\\Services\\ForumService');
final class InstallerGateLanguage {
    public function catalog(): array {return [['code'=>'pl'],['code'=>'en']];}
    public function activate(string $locale): void {if($locale!=='pl') throw new RuntimeException('Unexpected locale');}
}
class_alias(InstallerGateLanguage::class, 'App\\Services\\LanguageRegistry');
final class InstallerGateArtisan {
    public function call(string $command,array $parameters=[]): never {
        if($command!=='migrate'||$parameters!==['--force'=>true]) throw new RuntimeException('Unexpected artisan command');
        ++InstallerGateState::$migrations;
        throw new RuntimeException('STOP_AT_MIGRATION');
    }
}
final class InstallerGateSchema {
    public function getTableListing($schema=null,$schemaQualified=true): array {
        return InstallerGateState::$usersTable ? ['users'] : [];
    }
    public function hasTable(string $table): bool {
        if($table!=='users') throw new RuntimeException('Unexpected schema lookup: '.$table);
        ++InstallerGateState::$schemaChecks;
        return InstallerGateState::$usersTable;
    }
}
final class InstallerGateDbQuery {
    public function exists(): bool {++InstallerGateState::$userQueries;return InstallerGateState::$occupied;}
}
final class InstallerGateDb {
    public function table(string $table): InstallerGateDbQuery {
        if($table!=='users') throw new RuntimeException('Unexpected table: '.$table);
        return new InstallerGateDbQuery();
    }
    public function transaction(callable $callback): mixed {return $callback();}
}
final class InstallerGateRequest extends Request {
    public function validate(array $rules, ...$params): array {
        return ['install_token'=>InstallerGateState::$validToken?'correct-token':'wrong-token',
            'site_name'=>'Local Test','name'=>'Owner','email'=>'owner@example.test',
            'password'=>'secure-test-passphrase-2026', 'locale'=>'pl', 'setup_mode'=>'fresh'];
    }
}
final class InstallerGateApp extends Container {
    public function __construct(public string $testStorage) {}
    public function abort(int $status,string $message='',array $headers=[]): never {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message,null,$headers);
    }
    public function storagePath(string $path=''): string {return $this->testStorage.($path!==''?'/'.$path:'');}
}
$root=sys_get_temp_dir().'/veyra-installer-gate-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);
file_put_contents($root.'/install-token','correct-token');
$app=new InstallerGateApp($root);
Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string {return $key;}});
$app->instance(InstallerGateLanguage::class,new InstallerGateLanguage());
Artisan::swap(new InstallerGateArtisan());Schema::swap(new InstallerGateSchema());DB::swap(new InstallerGateDb());
$checks=0;
try {
    foreach ([
        ['occupied user database',false,true,true,true,409,0,1],
        ['empty initialized users table',false,true,false,true,'STOP_AT_MIGRATION',1,1],
        ['fresh database without users table',false,false,false,true,'STOP_AT_MIGRATION',1,0],
        ['already installed',true,true,true,true,409,0,0],
        ['invalid owner token',false,true,true,false,403,0,0],
    ] as [$label,$installed,$hasTable,$occupied,$validToken,$expected,$expectedMigrations,$expectedQueries]) {
        InstallerGateState::$installed=$installed;
        InstallerGateState::$usersTable=$hasTable;
        InstallerGateState::$occupied=$occupied;
        InstallerGateState::$validToken=$validToken;
        InstallerGateState::$schemaChecks=InstallerGateState::$userQueries=InstallerGateState::$migrations=0;
        $request=InstallerGateRequest::create('/install','POST');
        $session=new Store('install-gate-smoke',new ArraySessionHandler(60));
        $session->start();$request->setLaravelSession($session);
        try { (new InstallController())->store($request);$actual='RETURNED'; }
        catch (HttpExceptionInterface $exception) {$actual=$exception->getStatusCode();}
        catch (RuntimeException $exception) {$actual=$exception->getMessage();}
        if ($actual!==$expected || InstallerGateState::$migrations!==$expectedMigrations
            || InstallerGateState::$userQueries!==$expectedQueries) {
            fwrite(STDERR, "RED: $label: expected ".json_encode([$expected,$expectedMigrations,$expectedQueries]).
                ' got '.json_encode([$actual,InstallerGateState::$migrations,InstallerGateState::$userQueries])."\n");
            exit(1);
        }
        $checks+=3;
    }
    echo "PASS: $checks real PHP installer/fake schema+migrations assertions; NO PDO/HTTP/migration execution\n";
} finally {
    @unlink($root.'/install-token');@unlink($root.'/install.lock');@rmdir($root);
}
