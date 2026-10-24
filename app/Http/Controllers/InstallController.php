<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DemoSeeder;
use App\Services\ForumService as Forum;
use App\Services\LanguageRegistry;
use App\Services\InstallDatabaseGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class InstallController extends Controller
{
    public function show()
    {
        if (Forum::installed()) {
            return redirect('/');
        }

        return view('install', [
            'languages' => app(LanguageRegistry::class)->catalog(),
            'checks' => [
                __('install.checks.php') => PHP_VERSION_ID >= 80300,
                __('install.checks.database_driver') => extension_loaded('pdo_sqlite') || extension_loaded('pdo_mysql'),
                __('install.checks.argon2id') => in_array('argon2id', password_algos(), true),
                __('install.checks.storage') => is_writable(storage_path()),
                __('install.checks.database') => is_writable(database_path()),
            ],
        ]);
    }

    public function store(Request $request)
    {
        abort_if(Forum::installed(), 409, __('install.errors.already_installed'));

        $languages = app(LanguageRegistry::class);
        $data = $request->validate([
            'install_token' => 'required|string|max:100',
            'site_name' => 'required|string|min:2|max:80',
            'name' => 'required|string|min:2|max:50',
            'email' => 'required|email:rfc|max:254',
            'password' => ['required', 'string', 'confirmed', 'max:128', Password::min(12)],
            'locale' => ['sometimes', 'required', 'string', Rule::in(array_column($languages->catalog(), 'code'))],
            'setup_mode' => ['sometimes', 'required', Rule::in(['fresh', 'migrate'])],
            'demo' => 'sometimes|boolean',
        ]);
        $locale = $data['locale'] ?? app()->getLocale();
        $mode = $data['setup_mode'] ?? 'fresh';
        $demo = $request->boolean('demo');
        $languages->activate($locale);
        $request->session()->put('locale', $locale);

        if ($mode === 'migrate' && $demo) {
            throw ValidationException::withMessages(['demo' => __('install.errors.demo_migration')]);
        }

        $tokenPath = storage_path('install-token');
        abort_unless(
            is_file($tokenPath) && hash_equals(trim(file_get_contents($tokenPath)), $data['install_token']),
            403,
            __('install.errors.invalid_token'),
        );

        $lock = fopen(storage_path('install.lock'), 'c');
        abort_unless($lock && flock($lock, LOCK_EX), 503, __('install.errors.lock'));
        try {
            abort_if(Forum::installed(), 409, __('install.errors.already_installed'));
            // Check before running ANY migrations: an occupied shared/legacy
            // database must not be modified just because its forum has not
            // set WojteX's setup_complete flag. Keep the post-migrate check
            // below as a second gate for a concurrent occupant.
            abort_if(Schema::hasTable('users') && DB::table('users')->exists(),
                409, __('install.errors.occupied_database'));
            InstallDatabaseGuard::assertSafeBeforeMigrate();
            Artisan::call('migrate', ['--force' => true]);
            $user = DB::transaction(function () use ($data, $locale, $mode, $demo) {
                // An occupied database must never be claimed by a new installer visitor.
                abort_if(DB::table('users')->exists(), 409, __('install.errors.occupied_database'));

                $user = new User();
                $user->name = $data['name'];
                $user->email = mb_strtolower($data['email']);
                $user->password = $data['password'];
                $user->role = 'admin';
                $user->color = 'violet';
                $user->save();

                Forum::setSetting('site_name', $data['site_name']);
                Forum::setSetting('default_locale', $locale);
                Forum::setSetting('setup_mode', $mode);
                Forum::setSetting('demo_content', false);
                foreach (['tagline', 'welcome_title', 'welcome_text'] as $key) {
                    Forum::setSetting($key, __('seed.'.$key));
                }
                foreach (['shoutbox', 'rankings', 'welcome'] as $id) {
                    DB::table('plugin_states')->insert([
                        'key' => $id, 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                foreach ([
                    ['community', 'chat', 'violet'],
                    ['projects', 'code', 'cyan'],
                    ['gaming', 'game', 'amber'],
                    ['help', 'book', 'green'],
                ] as $i => [$key, $icon, $color]) {
                    $name = __('seed.categories.'.$key.'.name');
                    DB::table('categories')->insert([
                        'name' => $name,
                        'slug' => Str::slug($name) ?: 'category-'.($i + 1),
                        'description' => __('seed.categories.'.$key.'.description'),
                        'icon' => $icon,
                        'color' => $color,
                        'sort_order' => $i,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
                if ($demo) {
                    app(DemoSeeder::class)->seed($user->id);
                }
                Forum::setSetting('setup_complete', true);
                Forum::audit('forum.installed', ['demo' => $demo, 'setup_mode' => $mode, 'locale' => $locale], $user->id);

                return $user;
            });

            file_put_contents(storage_path('installed.lock'), now()->toIso8601String(), LOCK_EX);
            if (is_file($tokenPath)) {
                unlink($tokenPath);
            }
            Auth::login($user);
            $request->session()->regenerate();

            return redirect('/');
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
