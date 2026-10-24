<?php
/**
 * Invoke real InstallController::store before migration against a synthetic
 * partially occupied / foreign schema. Artisan is a STOP-only fake.
 * This test deliberately does NOT exercise PHP PDO, HTTP or migrations.
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

final class ForeignInstallState {
    public static array $tables = [];
    public static array $nonempty = [];
    public static array $migrations = [];
    public static int $attempts = 0;
    public static int $checks = 0;
}
final class ForeignInstallForum { public static function installed(): bool {return false;} }
class_alias(ForeignInstallForum::class, 'App\\Services\\ForumService');
final class ForeignInstallLanguage {
    public function catalog(): array {return [['code'=>'pl'],['code'=>'en']];}
    public function activate(string $locale): void {if($locale!=='pl') throw new RuntimeException('Wrong locale');}
}
class_alias(ForeignInstallLanguage::class, 'App\\Services\\LanguageRegistry');
final class ForeignInstallArtisan {
    public function call(string $command, array $parameters=[]): never {
        if ($command!=='migrate' || $parameters!==['--force'=>true]) throw new RuntimeException('unexpected command');
        ++ForeignInstallState::$attempts;
        throw new RuntimeException('STOP_AT_MIGRATION');
    }
}
final class ForeignInstallSchema {
    public function hasTable(string $name): bool {return in_array($name,ForeignInstallState::$tables,true);}
    public function getTableListing($schema=null,$schemaQualified=true): array {
        ++ForeignInstallState::$checks;
        if($schema !== null || $schemaQualified !== false) throw new RuntimeException('expected unqualified listing');
        return ForeignInstallState::$tables;
    }
}
final class ForeignInstallQuery {
    private ?array $migrationNames=null;
    public function __construct(private string $table) {}
    public function whereNotIn(string $field, array $names): self {
        if ($this->table!=='migrations'||$field!=='migration') throw new RuntimeException('unexpected whereNotIn');
        $this->migrationNames=$names;
        return $this;
    }
    public function exists(): bool {
        ++ForeignInstallState::$checks;
        if ($this->migrationNames !== null) {
            foreach (ForeignInstallState::$migrations as $name) if (!in_array($name,$this->migrationNames,true)) return true;
            return false;
        }
        return !empty(ForeignInstallState::$nonempty[$this->table]);
    }
}
final class ForeignInstallDb {
    public function table(string $table): ForeignInstallQuery {
        if(!in_array($table, ForeignInstallState::$tables, true)) throw new RuntimeException('Queried absent table '.$table);
        return new ForeignInstallQuery($table);
    }
}
final class ForeignInstallRequest extends Request {
    public function validate(array $rules,...$params): array {
        return ['install_token'=>'test-token','site_name'=>'Test Forum','name'=>'Owner',
            'email'=>'owner@example.test','password'=>'correct horse battery staple 2026',
            'locale'=>'pl','setup_mode'=>'fresh'];
    }
}
final class ForeignInstallApp extends Container {
    public function __construct(public string $testStorage) {}
    public function abort(int $status,string $message='',array $headers=[]): never {
        throw new \Symfony\Component\HttpKernel\Exception\HttpException($status,$message,null,$headers);
    }
    public function storagePath(string $path=''): string {return $this->testStorage.($path!==''?'/'.$path:'');}
    public function databasePath(string $path=''): string {return dirname(__DIR__,2).'/database'.($path!==''?'/'.$path:'');}
}
$root=sys_get_temp_dir().'/veyra-foreign-install-'.bin2hex(random_bytes(6));
mkdir($root,0700,true);file_put_contents($root.'/install-token','test-token');
$app=new ForeignInstallApp($root);Container::setInstance($app);Facade::setFacadeApplication($app);
$app->instance('translator',new class {public function get(string $key): string {return $key;}});
$app->instance(ForeignInstallLanguage::class,new ForeignInstallLanguage());
Artisan::swap(new ForeignInstallArtisan());Schema::swap(new ForeignInstallSchema());DB::swap(new ForeignInstallDb());
$checks=0;
try {
    foreach ([
        ['other WojteX data but no users',['users','categories'],['categories'=>true],[],409,0],
        ['empty MyBB legacy table',['mybb_users'],[],[],409,0],
        ['foreign Laravel migration',['migrations'],[],['2019_01_01_123456_create_other_table'],409,0],
        ['empty initialized WojteX',['users','categories','migrations'],[],['0001_01_01_000000_create_users_table'],'STOP_AT_MIGRATION',1],
        ['pristine DB',[],[],[],'STOP_AT_MIGRATION',1],
    ] as [$name,$tables,$nonempty,$migrations,$expected,$calls]) {
        ForeignInstallState::$tables=$tables;
        ForeignInstallState::$nonempty=$nonempty;
        ForeignInstallState::$migrations=$migrations;
        ForeignInstallState::$attempts=ForeignInstallState::$checks=0;
        $request=ForeignInstallRequest::create('/install','POST');
        $session=new Store('foreign-install-test',new ArraySessionHandler(60));
        $session->start();$request->setLaravelSession($session);
        try {(new InstallController())->store($request);$actual='RETURNED';}
        catch(HttpExceptionInterface $e){$actual=$e->getStatusCode();}
        catch(RuntimeException $e){$actual=$e->getMessage();}
        if($actual!==$expected || ForeignInstallState::$attempts!==$calls){
            fwrite(STDERR,'RED: '.$name.' expected '.json_encode([$expected,$calls]).
                ' got '.json_encode([$actual,ForeignInstallState::$attempts])."\n");exit(1);
        }
        $checks+=2;
    }
    echo "PASS: $checks real PHP controller/fake schema+Artisan assertions; NO PDO/HTTP/migration execution\n";
} finally {
    @unlink($root.'/install-token');@unlink($root.'/install.lock');@rmdir($root);
}
