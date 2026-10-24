<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;

class ExtensionRegistry
{
    public static function catalog(): array
    {
        $states=DB::table('plugin_states')->pluck('enabled','key')->all(); $items=[];
        foreach(glob(base_path('extensions/*/plugin.json'))?:[] as $path) {
            try { $p=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR); } catch(\Throwable) { continue; }
            if(!isset($p['id'],$p['name'],$p['version']) || !preg_match('/^[a-z][a-z0-9-]{1,50}$/D',$p['id']) || basename(dirname($path))!==$p['id'] || ($p['api']??null)!==1)continue;
            if (in_array($p['id'], ['rankings','shoutbox','welcome','welcome-message'], true)) {
                foreach (['name','description'] as $field) {
                    $key = 'extension.'.$p['id'].'.'.$field;
                    if (app('translator')->has($key)) $p[$field] = __($key);
                }
            }
            $items[]=['id'=>$p['id'],'name'=>$p['name'],'description'=>$p['description']??'','version'=>$p['version'],'enabled'=>(bool)($states[$p['id']]??false),'api'=>1,'entry'=>$p['entry']??null];
        }
        return array_map(function($p){unset($p['entry']);return $p;},$items);
    }
    public static function enabled(string $id): bool { return (bool)DB::table('plugin_states')->where('key',$id)->value('enabled'); }

    public static function boot(): void
    {
        if(!ForumService::installed())return;
        foreach(self::catalog() as $plugin) {
            if(!$plugin['enabled'])continue;
            $dir=base_path('extensions/'.$plugin['id']);
            $manifest=json_decode(file_get_contents($dir.'/plugin.json'),true);
            $entry=$manifest['entry']??null;
            if(!$entry || !preg_match('/^[a-zA-Z0-9_-]+\.php$/D',$entry))continue;
            $resolved=realpath($dir.'/'.$entry); $root=realpath($dir);
            if(!$resolved || !$root || dirname($resolved)!==$root)continue;
            try {
                $boot=require $resolved;
                if(is_callable($boot))$boot();
            } catch(\Throwable $e) {
                // A broken extension must not make recovery/admin access impossible.
                report($e);
                DB::table('plugin_states')->where('key',$plugin['id'])->update(['enabled'=>false,'updated_at'=>now()]);
            }
        }
    }
}
