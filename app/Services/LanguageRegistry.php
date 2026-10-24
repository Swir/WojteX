<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use JsonException;

/** Data-only language packs. Files never contain or execute PHP/JavaScript. */
class LanguageRegistry
{
    public const MAX_BYTES = 1048576;
    private ?array $builtins = null;

    private function builtins(): array
    {
        if ($this->builtins !== null) return $this->builtins;
        $items = [];
        foreach (['pl', 'en'] as $code) {
            $directory = lang_path($code);
            $metadata = json_decode(file_get_contents($directory.'/manifest.json'), true, 8, JSON_THROW_ON_ERROR);
            $messages = [];
            foreach (['ui', 'install', 'server', 'import'] as $domain) {
                $file = $directory.'/'.$domain.'.json';
                if (is_file($file)) $messages = array_replace($messages, json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR));
            }
            $items[$code] = $metadata + ['messages' => $messages];
        }
        return $this->builtins = $items;
    }

    private function directory(): string
    {
        return rtrim((string) config('veyra.language_path', storage_path('app/private/languages')), DIRECTORY_SEPARATOR);
    }

    public static function validCode(mixed $code): bool
    {
        // Supported BCP 47 subset: language, optional script, optional region.
        return is_string($code) && preg_match('/^[a-z]{2,3}(?:-[a-z]{4})?(?:-(?:[a-z]{2}|[0-9]{3}))?$/D', $code) === 1;
    }

    private function packs(): array
    {
        $packs = $this->builtins();
        foreach (glob($this->directory().'/*.json') ?: [] as $file) {
            $code = pathinfo($file, PATHINFO_FILENAME);
            if (!self::validCode($code) || isset($packs[$code]) || filesize($file) > self::MAX_BYTES) continue;
            try {
                $pack = $this->parse(file_get_contents($file), false);
                if ($pack['locale'] === $code) $packs[$code] = $pack;
            } catch (\Throwable) {
                // A damaged optional translation cannot take down the application.
            }
        }
        return $packs;
    }

    public function catalog(): array
    {
        $total = max(1, count($this->builtins()['en']['messages']));
        $items = [];
        foreach ($this->packs() as $code => $pack) {
            $translated = count(array_intersect_key($pack['messages'], $this->builtins()['en']['messages']));
            $items[] = ['code' => $code, 'name' => $pack['name'], 'native_name' => $pack['native_name'], 'version' => $pack['version'], 'builtin' => in_array($code, ['pl','en'], true), 'coverage' => (int) floor($translated * 100 / $total), 'missing_count' => max(0, $total - $translated)];
        }
        return $items;
    }

    public function has(string $locale): bool
    {
        return self::validCode($locale) && isset($this->packs()[$locale]);
    }

    public function defaultLocale(): string
    {
        try { $code = json_decode(DB::table('settings')->where('key','default_locale')->value('value') ?? '"pl"', true); }
        catch (\Throwable) { $code = 'pl'; }
        return is_string($code) && $this->has($code) ? $code : 'pl';
    }

    public function messages(string $locale): array
    {
        $packs = $this->packs();
        return array_replace($packs['en']['messages'], $packs[$locale]['messages'] ?? []);
    }

    public function activate(string $locale): void
    {
        $locale = $this->has($locale) ? $locale : $this->defaultLocale();
        app()->setLocale($locale);
        $translator = app('translator');
        $translator->setFallback('en');
        foreach (array_unique(['en','pl',$locale]) as $code) $translator->addLines($this->messages($code), $code);
    }

    public function payload(?string $locale = null): array
    {
        $locale ??= app()->getLocale();
        if (!$this->has($locale)) $locale = $this->defaultLocale();
        return ['locale'=>$locale, 'fallback_locale'=>'en', 'languages'=>$this->catalog(), 'messages'=>$this->messages($locale)];
    }

    public function export(string $locale): array
    {
        $pack = $this->packs()[$locale] ?? null;
        if (!$pack) $this->fail('server.language_unknown');
        return ['schema'=>1, 'locale'=>$locale, 'name'=>$pack['name'], 'native_name'=>$pack['native_name'], 'version'=>$pack['version'], 'messages'=>$this->messages($locale)];
    }

    public function install(string $json, bool $replace = false): array
    {
        $pack = $this->parse($json, true);
        $encoded = json_encode($pack, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        // The stored form must also fit the reader's limit, before any mutation.
        if (strlen($encoded) > self::MAX_BYTES) $this->fail('server.language_too_large');
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) throw new \RuntimeException('Cannot create private language directory.');
        $lock = fopen($directory.'/.install.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Cannot lock language installation.');
        $temporary = null;
        try {
            $target = $directory.'/'.$pack['locale'].'.json';
            if (is_file($target) && !$replace) $this->fail('server.language_exists');
            $temporary = tempnam($directory, '.pack-');
            if ($temporary === false) throw new \RuntimeException('Cannot prepare language file.');
            if (file_put_contents($temporary, $encoded, LOCK_EX) === false || !chmod($temporary, 0600) || !rename($temporary, $target)) throw new \RuntimeException('Cannot save language file.');
            $temporary = null;
        } finally {
            if ($temporary && is_file($temporary)) unlink($temporary);
            flock($lock, LOCK_UN); fclose($lock);
        }
        foreach ($this->catalog() as $item) if ($item['code'] === $pack['locale']) return $item;
        throw new \RuntimeException('Saved language could not be loaded.');
    }

    private function parse(string $json, bool $installing): array
    {
        if (strlen($json) > self::MAX_BYTES) $this->fail('server.language_too_large');
        if (!mb_check_encoding($json, 'UTF-8')) $this->fail('server.language_invalid_json');
        if (str_starts_with($json, "\xEF\xBB\xBF")) $json = substr($json, 3);
        try { $pack = json_decode($json, true, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException) { $this->fail('server.language_invalid_json'); }
        if (!is_array($pack) || array_is_list($pack) || ($pack['schema'] ?? null) !== 1 || !self::validCode($pack['locale'] ?? null)) $this->fail('server.language_invalid_schema');
        if (array_diff(array_keys($pack), ['schema','locale','name','native_name','version','messages'])) $this->fail('server.language_invalid_schema');
        if ($installing && in_array($pack['locale'], ['pl','en'], true)) $this->fail('server.language_builtin');
        foreach (['name','native_name'] as $field) if (!$this->plainText($pack[$field] ?? null, 80) || trim($pack[$field]) === '') $this->fail('server.language_invalid_schema');
        if (!is_string($pack['version'] ?? null) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.+_-]{0,39}$/D', $pack['version'])) $this->fail('server.language_invalid_schema');
        $messages = $pack['messages'] ?? null;
        if (!is_array($messages) || !$messages || array_is_list($messages) || count($messages) > 5000) $this->fail('server.language_invalid_messages');
        $english = $this->builtins()['en']['messages'];
        foreach ($messages as $key => $value) {
            if (!is_string($key) || !array_key_exists($key, $english)) $this->fail('server.language_unknown_key', ['key'=>(string)$key]);
            if (!$this->plainText($value, 8000) || trim($value) === '') $this->fail('server.language_plain_text', ['key'=>$key]);
            if ($this->placeholders($value) !== $this->placeholders($english[$key])) $this->fail('server.language_placeholders', ['key'=>$key]);
        }
        return ['schema'=>1, 'locale'=>$pack['locale'], 'name'=>trim($pack['name']), 'native_name'=>trim($pack['native_name']), 'version'=>$pack['version'], 'messages'=>$messages];
    }

    private function plainText(mixed $value, int $limit): bool
    {
        return is_string($value) && mb_strlen($value) <= $limit && !preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|<\s*[!\/?a-zA-Z]/u', $value);
    }

    private function placeholders(string $value): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches);
        $names = array_values(array_unique($matches[1])); sort($names); return $names;
    }

    private function fail(string $key, array $replace = []): never
    {
        throw ValidationException::withMessages(['file' => [__($key, $replace)]]);
    }
}
