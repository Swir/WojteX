<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LanguageRegistry;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** HTTP-level language behavior, plus the shipped catalog contract. */
class LanguageTest extends TestCase
{
    use RefreshDatabase;

    private string $languageDirectory;
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->languageDirectory = sys_get_temp_dir().'/veyra-language-test-'.bin2hex(random_bytes(8));
        mkdir($this->languageDirectory, 0700, true);
        config(['veyra.language_path' => $this->languageDirectory]);
        $this->setting('setup_complete', true);
        $this->setting('registration_open', true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->languageDirectory);
        parent::tearDown();
    }

    public function test_builtin_catalogs_have_matching_nonempty_keys_and_placeholders(): void
    {
        $catalogs = [];
        foreach (['en', 'pl'] as $locale) {
            $messages = [];
            foreach (['ui', 'install', 'server', 'import'] as $group) {
                $file = lang_path($locale.'/'.$group.'.json');
                $this->assertFileExists($file);
                $messages = array_replace($messages, json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR));
            }
            ksort($messages);
            $catalogs[$locale] = $messages;
        }
        $this->assertGreaterThan(100, count($catalogs['en']));
        $this->assertSame(array_keys($catalogs['en']), array_keys($catalogs['pl']));
        $invalid = [];
        foreach ($catalogs['en'] as $key => $english) {
            $polish = $catalogs['pl'][$key] ?? null;
            if (!is_string($english) || !is_string($polish) || trim($english) === '' || trim($polish) === '') {
                $invalid[] = $key.' is not nonempty text';
                continue;
            }
            if ($this->parameters($english) !== $this->parameters($polish)) $invalid[] = $key.' has different parameters';
        }
        $this->assertSame([], $invalid);
    }

    public function test_existing_alpha1_installation_defaults_to_polish_without_a_new_database_field(): void
    {
        $this->assertDatabaseMissing('settings', ['key' => 'default_locale']);
        $response = $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('i18n.locale', 'pl');
        $languages = collect($response->json('i18n.languages'))->keyBy('code');
        $this->assertTrue((bool) $languages['pl']['builtin']);
        $this->assertTrue((bool) $languages['en']['builtin']);
        $this->assertSame(0, $languages['pl']['missing_count']);
        $this->assertSame(0, $languages['en']['missing_count']);
        $this->assertNotEmpty($response->json('i18n.messages'));
    }

    public function test_guest_can_select_english_and_the_choice_survives_next_request(): void
    {
        $this->postJson('/api/locale', ['locale' => 'en'])->assertSuccessful()->assertJsonPath('i18n.locale', 'en');
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('i18n.locale', 'en');
        $this->get('/')->assertOk()->assertSee('lang="en"', false);
        $this->assertDatabaseMissing('settings', ['key' => 'default_locale']);
        $this->postJson('/api/locale', ['locale' => 'pl'])->assertSuccessful()->assertJsonPath('i18n.locale', 'pl');
        $this->get('/')->assertOk()->assertSee('lang="pl"', false);
    }

    public function test_valid_header_wins_over_session_but_invalid_hints_do_not_become_paths(): void
    {
        $this->setting('default_locale', 'en');
        $this->postJson('/api/locale', ['locale' => 'pl'])->assertSuccessful();
        $this->getJson('/api/bootstrap', ['X-WojteX-Locale' => 'en'])->assertJsonPath('i18n.locale', 'en');
        $response = $this->getJson('/api/bootstrap', ['X-WojteX-Locale' => '../../outside'])->assertOk();
        $this->assertSame('pl', $response->json('i18n.locale'));
        $this->assertSame([], glob($this->languageDirectory.'/*.json') ?: []);
    }

    public function test_invalid_locale_changes_return_validation_errors_and_leave_preference_unchanged(): void
    {
        $this->postJson('/api/locale', ['locale' => 'en'])->assertSuccessful();
        foreach ([[], ['locale' => '../pl'], ['locale' => 'zz'], ['locale' => ['pl']]] as $input) {
            $this->postJson('/api/locale', $input)->assertUnprocessable()->assertJsonValidationErrors('locale');
        }
        $this->getJson('/api/bootstrap')->assertJsonPath('i18n.locale', 'en');
    }

    public function test_forum_default_can_be_changed_by_admin_and_applies_to_a_new_guest(): void
    {
        $admin = $this->user('admin');
        $this->actingAs($admin)->patchJson('/api/admin/settings', ['default_locale' => 'en'])
            ->assertSuccessful()->assertJsonPath('settings.default_locale', 'en');
        $this->assertDatabaseHas('settings', ['key' => 'default_locale', 'value' => '"en"']);
        auth()->logout();
        session()->flush();
        $this->getJson('/api/bootstrap')->assertJsonPath('i18n.locale', 'en');
        $this->get('/')->assertSee('lang="en"', false);
    }

    public function test_default_locale_setting_rejects_unknown_or_array_values(): void
    {
        $this->actingAs($this->user('admin'));
        foreach (['not-installed', '../en', ['en']] as $locale) {
            $this->patchJson('/api/admin/settings', ['default_locale' => $locale])
                ->assertUnprocessable()->assertJsonValidationErrors('default_locale');
        }
        $this->assertDatabaseMissing('settings', ['key' => 'default_locale']);
    }

    public function test_validation_and_application_errors_follow_the_selected_language(): void
    {
        $input = ['name' => 'Language tester', 'email' => 'invalid-email', 'password' => 'LongTestPassword!2026', 'password_confirmation' => 'LongTestPassword!2026'];
        $english = $this->postJson('/api/auth/register', $input, ['X-WojteX-Locale' => 'en'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->json('errors.email.0');
        $polish = $this->postJson('/api/auth/register', $input, ['X-WojteX-Locale' => 'pl'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->json('errors.email.0');
        $this->assertIsString($english);
        $this->assertIsString($polish);
        $this->assertNotSame($english, $polish);
        $this->assertStringNotContainsString('validation.', $english.$polish);
        $this->assertStringNotContainsString(':attribute', $english.$polish);

        $enMissing = $this->getJson('/api/threads/999999', ['X-WojteX-Locale' => 'en'])->assertNotFound()->json('message');
        $plMissing = $this->getJson('/api/threads/999999', ['X-WojteX-Locale' => 'pl'])->assertNotFound()->json('message');
        $this->assertIsString($enMissing);
        $this->assertIsString($plMissing);
        $this->assertNotSame($enMissing, $plMissing);
        $this->assertStringNotContainsString('server.', $enMissing.$plMissing);
    }

    public function test_locale_does_not_translate_or_modify_stored_community_content(): void
    {
        $owner = $this->user('admin');
        $category = DB::table('categories')->insertGetId(['name' => 'Nasz własny dział', 'slug' => 'original', 'description' => 'User-owned description', 'icon' => 'chat', 'color' => 'violet', 'is_private' => false, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $thread = DB::table('threads')->insertGetId(['category_id' => $category, 'user_id' => $owner->id, 'title' => 'Mój temat — my original title', 'slug' => 'original-title', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('posts')->insert(['thread_id' => $thread, 'user_id' => $owner->id, 'body' => 'Polski tekst i English content pozostają moje.', 'body_format' => 'markdown', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['pl', 'en'] as $locale) {
            $this->getJson('/api/threads/'.$thread, ['X-WojteX-Locale' => $locale])->assertOk()
                ->assertJsonPath('thread.title', 'Mój temat — my original title')
                ->assertJsonPath('thread.category.name', 'Nasz własny dział')
                ->assertJsonPath('posts.0.body', 'Polski tekst i English content pozostają moje.');
        }
        $this->assertDatabaseHas('categories', ['id' => $category, 'name' => 'Nasz własny dział']);
    }

    public function test_only_admin_can_list_export_install_or_set_default_languages(): void
    {
        $this->getJson('/api/admin/languages')->assertUnauthorized();
        $this->getJson('/api/admin/languages/template')->assertUnauthorized();
        $this->upload($this->pack())->assertUnauthorized();
        foreach (['member', 'moderator'] as $role) {
            $this->actingAs($this->user($role));
            $this->getJson('/api/admin/languages')->assertForbidden();
            $this->getJson('/api/admin/languages/template')->assertForbidden();
            $this->upload($this->pack())->assertForbidden();
            $this->patchJson('/api/admin/settings', ['default_locale' => 'en'])->assertForbidden();
        }
        $this->assertSame([], glob($this->languageDirectory.'/*.json') ?: []);
    }

    public function test_admin_can_install_a_partial_language_with_english_fallback_and_persistent_catalog(): void
    {
        $this->actingAs($this->user('admin'));
        $pack = $this->pack();
        $key = array_key_first($pack['messages']);
        $this->upload($pack)->assertSuccessful()->assertJsonPath('language.code', 'de');
        $this->assertFileExists($this->languageDirectory.'/de.json');
        $languages = $this->getJson('/api/admin/languages')->assertOk()->json('languages');
        $german = collect($languages)->firstWhere('code', 'de');
        $this->assertNotNull($german);
        $this->assertFalse((bool) $german['builtin']);
        $this->assertGreaterThan(0, $german['missing_count']);
        $this->assertLessThan(100, $german['coverage']);
        $this->assertSame('Deutsch', $german['native_name']);

        $this->app->forgetInstance(LanguageRegistry::class);
        auth()->logout();
        $response = $this->postJson('/api/locale', ['locale' => 'de'])->assertSuccessful();
        $messages = $response->json('i18n.messages');
        $this->assertSame($pack['messages'][$key], $messages[$key]);
        $english = app(LanguageRegistry::class)->messages('en');
        $other = array_values(array_diff(array_keys($english), [$key]))[0];
        $this->assertSame($english[$other], $messages[$other]);
        $this->getJson('/api/bootstrap')->assertJsonPath('i18n.locale', 'de');
    }

    public function test_admin_can_set_a_newly_installed_language_as_forum_default(): void
    {
        $this->actingAs($this->user('admin'));
        $this->upload($this->pack())->assertSuccessful();
        $this->patchJson('/api/admin/settings', ['default_locale' => 'de'])->assertSuccessful()->assertJsonPath('settings.default_locale', 'de');
        auth()->logout();
        session()->flush();
        $this->getJson('/api/bootstrap')->assertJsonPath('i18n.locale', 'de');
    }

    public function test_regional_locale_tags_can_be_installed_and_selected(): void
    {
        $this->actingAs($this->user('admin'));
        $pack = $this->pack();
        $pack['locale'] = 'pt-br';
        $pack['name'] = 'Brazilian Portuguese';
        $pack['native_name'] = 'Português do Brasil';
        $this->upload($pack)->assertSuccessful()->assertJsonPath('language.code', 'pt-br');
        $this->postJson('/api/locale', ['locale' => 'pt-br'])->assertSuccessful()->assertJsonPath('i18n.locale', 'pt-br');
        $this->getJson('/api/bootstrap')->assertJsonPath('i18n.locale', 'pt-br');
    }

    public function test_existing_custom_pack_requires_explicit_replace_and_validation_is_atomic(): void
    {
        $this->actingAs($this->user('admin'));
        $pack = $this->pack();
        $key = array_key_first($pack['messages']);
        $this->upload($pack)->assertSuccessful();
        $before = file_get_contents($this->languageDirectory.'/de.json');
        $replacement = $pack;
        $replacement['messages'][$key] = 'Neue Übersetzung';
        $replacement['version'] = '1.1.0';
        $this->upload($replacement)->assertUnprocessable();
        $this->assertSame($before, file_get_contents($this->languageDirectory.'/de.json'));
        $invalid = $replacement;
        $invalid['messages'][$key] = '<script>alert(1)</script>';
        $this->upload($invalid, true)->assertUnprocessable();
        $this->assertSame($before, file_get_contents($this->languageDirectory.'/de.json'));
        $this->upload($replacement, true)->assertSuccessful();
        $this->assertSame('Neue Übersetzung', app(LanguageRegistry::class)->messages('de')[$key]);
    }

    #[DataProvider('invalidPacks')]
    public function test_invalid_packs_are_rejected_without_writing_files(string $case): void
    {
        $this->actingAs($this->user('admin'));
        $pack = $this->pack();
        $key = array_key_first($pack['messages']);
        match ($case) {
            'path' => $pack['locale'] = '../../outside',
            'invalid-locale-tag' => $pack['locale'] = 'de-12',
            'builtin' => $pack['locale'] = 'pl',
            'schema' => $pack['schema'] = 99,
            'unknown-key' => $pack['messages'] = ['ui.not_a_real_translation_key_qa' => 'Invalid key'],
            'html' => $pack['messages'][$key] = '<img src=x onerror=alert(1)>',
            'php' => $pack['messages'][$key] = '<?php echo "not a language"; ?>',
            'placeholder' => $pack['messages'][$key] = 'Unexpected :secret_parameter',
            'placeholder-case' => $pack['messages'] = ['ui.languages.installed' => 'Installiert: :NAME.'],
            'message-array' => $pack['messages'][$key] = ['not', 'text'],
            'metadata-html' => $pack['native_name'] = '<script>German</script>',
        };
        $this->upload($pack)->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame([], glob($this->languageDirectory.'/*.json') ?: []);
        $this->assertFalse(app(LanguageRegistry::class)->has('../../outside'));
    }

    public static function invalidPacks(): array
    {
        return array_map(fn ($case) => [$case], ['path', 'invalid-locale-tag', 'builtin', 'schema', 'unknown-key', 'html', 'php', 'placeholder', 'placeholder-case', 'message-array', 'metadata-html']);
    }

    public function test_malformed_json_and_oversized_upload_are_rejected(): void
    {
        $this->actingAs($this->user('admin'));
        $this->post('/api/admin/languages', ['file' => UploadedFile::fake()->createWithContent('bad.json', '{"locale":"de",broken')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/admin/languages', ['file' => UploadedFile::fake()->create('big.json', 1025, 'application/json')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame([], glob($this->languageDirectory.'/*.json') ?: []);
    }

    public function test_valid_pack_near_the_size_limit_remains_readable_after_storage_serialization(): void
    {
        $this->actingAs($this->user('admin'));
        $pack = $this->pack();
        $pack['messages'] = app(LanguageRegistry::class)->messages('en');
        $encode = static fn (array $value): string => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $target = 1048576 - 16;
        foreach ($pack['messages'] as $key => $value) {
            $remaining = $target - strlen($encode($pack));
            if ($remaining <= 0) break;
            // Pad legitimate messages while preserving their parameters and the per-value limit.
            $padding = min($remaining, max(0, 8000 - mb_strlen($value)));
            $pack['messages'][$key] .= $padding > 0 ? ' '.str_repeat('x', $padding - 1) : '';
        }
        $json = $encode($pack);
        $this->assertSame($target, strlen($json), 'Fixture must exercise serialization at the upload boundary.');
        $response = $this->post('/api/admin/languages', ['file' => UploadedFile::fake()->createWithContent('limit.json', $json)], ['Accept' => 'application/json']);
        $response->assertCreated()->assertJsonPath('language.code', 'de');
        $this->app->forgetInstance(LanguageRegistry::class);
        $this->assertTrue(app(LanguageRegistry::class)->has('de'));
        $this->assertSame($pack['messages'], app(LanguageRegistry::class)->messages('de'));
    }

    public function test_both_builtins_are_protected_even_with_replace_flag(): void
    {
        $this->actingAs($this->user('admin'));
        foreach (['en', 'pl'] as $locale) {
            $pack = $this->pack();
            $pack['locale'] = $locale;
            $this->upload($pack, true)->assertUnprocessable();
            $this->assertSame(0, collect(app(LanguageRegistry::class)->catalog())->firstWhere('code', $locale)['missing_count']);
        }
        $this->assertSame([], glob($this->languageDirectory.'/*.json') ?: []);
    }

    public function test_exported_template_is_a_downloadable_complete_pack_without_private_data(): void
    {
        $this->actingAs($this->user('admin'));
        $response = $this->get('/api/admin/languages/template?locale=en')->assertOk();
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $pack = json_decode($response->getContent(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $pack['schema']);
        $this->assertSame('en', $pack['locale']);
        $this->assertSame(app(LanguageRegistry::class)->messages('en'), $pack['messages']);
        $this->assertStringNotContainsString('language-member', $response->getContent());
        $this->assertArrayNotHasKey('password', $pack);
        $pack['locale'] = 'fr';
        $pack['name'] = 'French';
        $pack['native_name'] = 'Français';
        $this->upload($pack)->assertSuccessful()->assertJsonPath('language.code', 'fr');
        $this->assertSame(0, collect(app(LanguageRegistry::class)->catalog())->firstWhere('code', 'fr')['missing_count']);
    }

    public function test_invalid_template_locale_does_not_read_a_filesystem_path(): void
    {
        $this->actingAs($this->user('admin'));
        $this->getJson('/api/admin/languages/template?locale=../../.env')->assertUnprocessable();
    }

    public function test_locale_and_pack_upload_require_real_csrf_tokens(): void
    {
        $this->app->bind(PreventRequestForgery::class, EnforcedCsrfForLanguageTests::class);
        $english = $this->withSession(['_token' => 'language-test-token'])
            ->postJson('/api/locale', ['locale' => 'en'], ['X-WojteX-Locale' => 'en'])->assertStatus(419)->json('message');
        $polish = $this->withSession(['_token' => 'language-test-token'])
            ->postJson('/api/locale', ['locale' => 'en'], ['X-WojteX-Locale' => 'pl'])->assertStatus(419)->json('message');
        $this->assertIsString($english);
        $this->assertIsString($polish);
        $this->assertNotSame($english, $polish, 'The locale must be resolved before CSRF failures are rendered.');
        $this->withSession(['_token' => 'language-test-token'])->withHeader('X-CSRF-TOKEN', 'language-test-token')
            ->postJson('/api/locale', ['locale' => 'en'])->assertSuccessful();

        $this->actingAs($this->user('admin'));
        $this->withHeaders(['X-CSRF-TOKEN' => 'wrong-token']);
        $this->upload($this->pack())->assertStatus(419);
        $this->assertSame([], glob($this->languageDirectory.'/*.json') ?: []);
        $this->withHeaders(['X-CSRF-TOKEN' => 'language-test-token']);
        $this->upload($this->pack())->assertSuccessful();
    }

    private function setting(string $key, mixed $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value)]);
    }

    private function user(string $role = 'member'): User
    {
        $n = ++$this->sequence;
        $user = new User();
        $user->forceFill(['name' => 'Language member '.$n, 'email' => 'language-member'.$n.'@example.test', 'password' => 'SafeTestingPassword!2026', 'role' => $role, 'color' => 'violet', 'is_banned' => false])->save();
        return $user;
    }

    private function pack(): array
    {
        $messages = app(LanguageRegistry::class)->messages('en');
        foreach ($messages as $key => $value) {
            if (str_starts_with($key, 'ui.') && $this->parameters($value) === []) {
                return ['schema' => 1, 'locale' => 'de', 'name' => 'German', 'native_name' => 'Deutsch', 'version' => '1.0.0', 'messages' => [$key => 'Deutsche Übersetzung']];
            }
        }
        $this->fail('Expected at least one plain UI translation in the English catalog.');
    }

    private function upload(array $pack, bool $replace = false): TestResponse
    {
        return $this->post('/api/admin/languages', ['file' => UploadedFile::fake()->createWithContent('language.json', json_encode($pack, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)), 'replace' => $replace ? '1' : '0'], ['Accept' => 'application/json']);
    }

    private function parameters(string $value): array
    {
        preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches);
        $parameters = array_values(array_unique($matches[1]));
        sort($parameters);
        return $parameters;
    }
}

class EnforcedCsrfForLanguageTests extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
