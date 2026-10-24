<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallationTest extends TestCase
{
    use RefreshDatabase;

    private string $tempStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempStorage = sys_get_temp_dir().'/veyra-install-test-'.bin2hex(random_bytes(8));
        mkdir($this->tempStorage, 0700, true);
        $this->app->useStoragePath($this->tempStorage);
        file_put_contents($this->tempStorage.'/install-token', 'test-owner-install-code');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempStorage);
        parent::tearDown();
    }

    private function input(): array
    {
        return [
            'install_token' => 'test-owner-install-code',
            'site_name' => 'Test Community',
            'name' => 'Owner',
            'email' => 'owner@example.test',
            'password' => 'A long test password 123!',
            'password_confirmation' => 'A long test password 123!',
        ];
    }

    private function setting(string $key): mixed
    {
        return json_decode(DB::table('settings')->where('key', $key)->value('value') ?? 'null', true);
    }

    public function test_uninstalled_forum_redirects_to_setup_and_does_not_expose_token(): void
    {
        $this->get('/')->assertRedirect('/install');
        $this->get('/install')->assertOk()->assertDontSee('test-owner-install-code');
        $this->getJson('/api/bootstrap')->assertStatus(503);
    }

    public function test_only_owner_token_can_create_first_admin_and_setup_is_one_time(): void
    {
        $data = $this->input();
        $data['install_token'] = 'wrong';
        $this->post('/install', $data)->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        $this->post('/install', $this->input())->assertRedirect('/');
        $this->assertDatabaseHas('users', ['email' => 'owner@example.test', 'role' => 'admin']);
        $this->assertDatabaseCount('categories', 4);
        $this->assertDatabaseCount('posts', 0);
        $this->assertFileDoesNotExist($this->tempStorage.'/install-token');
        $this->post('/install', $this->input())->assertStatus(409);
        $this->assertDatabaseCount('users', 1);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.role', 'admin');
    }

    public function test_default_fresh_install_needs_no_import_and_creates_no_sample_content(): void
    {
        // Intentionally omit both demo and migration/source fields, as a new owner would.
        $this->post('/install', $this->input())->assertRedirect('/');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('categories', 4);
        foreach (['threads', 'posts', 'shouts', 'reactions', 'attachments', 'import_runs', 'import_maps'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame('fresh', $this->setting('setup_mode'));
        $this->assertFalse($this->setting('demo_content'));
        $this->assertSame('pl', $this->setting('default_locale'));
        $this->assertDatabaseHas('categories', ['name' => 'Rozmowy społeczności']);
        $this->assertSame('Dobre rozmowy zaczynają się tutaj.', $this->setting('welcome_title'));
    }

    public function test_installer_has_polish_and_english_and_remembers_language_without_credentials_in_url(): void
    {
        $this->get('/install?lang=en')
            ->assertOk()
            ->assertSee('lang="en"', false)
            ->assertSee('Fresh forum')
            ->assertSee('Installation language')
            ->assertSee('method="get" action="/install"', false)
            ->assertSee('method="post" action="/install"', false)
            ->assertDontSee('Nowe forum')
            ->assertDontSee('test-owner-install-code');
        $this->get('/install')->assertOk()->assertSee('Fresh forum');
        $this->get('/install?lang=pl')
            ->assertOk()
            ->assertSee('lang="pl"', false)
            ->assertSee('Nowe forum')
            ->assertSee('Język instalacji')
            ->assertDontSee('Fresh forum');
    }

    public function test_english_fresh_install_sets_language_and_initial_content_in_english(): void
    {
        $this->post('/install', $this->input() + ['locale' => 'en', 'setup_mode' => 'fresh'])->assertRedirect('/');
        $this->assertSame('en', $this->setting('default_locale'));
        $this->assertSame('Your people. Your ideas.', $this->setting('tagline'));
        $this->assertSame('Good conversations start here.', $this->setting('welcome_title'));
        $this->assertDatabaseHas('categories', ['name' => 'Community conversations']);
        $this->assertDatabaseMissing('categories', ['name' => 'Rozmowy społeczności']);
        $this->assertDatabaseCount('threads', 0);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('i18n.locale', 'en');
    }

    public function test_install_uses_previously_selected_language_when_post_locale_is_omitted(): void
    {
        $this->get('/install?lang=en')->assertOk();
        $this->post('/install', $this->input())->assertRedirect('/');
        $this->assertSame('en', $this->setting('default_locale'));
        $this->assertDatabaseHas('categories', ['name' => 'Guides and help']);
    }

    public function test_opt_in_demo_creates_real_data_without_shared_demo_credentials(): void
    {
        $this->post('/install', $this->input() + ['demo' => true])->assertRedirect('/');
        $this->assertDatabaseCount('users', 6);
        $this->assertDatabaseCount('threads', 8);
        $this->assertDatabaseCount('posts', 20);
        $this->getJson('/api/threads')->assertOk()->assertJsonPath('total', 8);
        $this->getJson('/api/shouts')->assertOk()->assertJsonCount(4, 'data');
        $this->assertSame(1, DB::table('users')->where('role', 'admin')->count());
        $this->assertDatabaseHas('threads', ['title' => 'Witaj w WojteX. Rozgość się i powiedz cześć!']);
        $this->assertTrue($this->setting('demo_content'));
        foreach (DB::table('users')->where('role', 'member')->pluck('password') as $password) {
            $this->assertStringStartsWith('$argon2id$', $password);
        }
        $this->assertSame(5, DB::table('users')->where('role', 'member')->distinct()->count('password'));
    }

    public function test_english_demo_uses_english_content_and_is_not_retranslated_by_visitor_language(): void
    {
        $this->post('/install', $this->input() + ['locale' => 'en', 'demo' => true])->assertRedirect('/');
        $this->assertDatabaseHas('threads', ['title' => 'Welcome to WojteX. Make yourself at home and say hello!']);
        $this->assertDatabaseHas('users', ['bio' => 'Sample profile — demonstration data.']);
        $this->assertDatabaseHas('shouts', ['body' => 'Hi everyone! Good to see you here 👋']);
        $this->assertStringContainsString("\n\n", DB::table('posts')->orderBy('id')->value('body'));
        $this->getJson('/api/bootstrap', ['X-WojteX-Locale' => 'pl'])
            ->assertOk()
            ->assertJsonPath('settings.welcome_title', 'Good conversations start here.');
        $this->assertDatabaseHas('categories', ['name' => 'Community conversations']);
    }

    public function test_migration_mode_prepares_a_new_forum_without_reading_source_data(): void
    {
        $this->post('/install', $this->input() + ['locale' => 'en', 'setup_mode' => 'migrate'])->assertRedirect('/');
        $this->assertSame('migrate', $this->setting('setup_mode'));
        $this->assertSame('en', $this->setting('default_locale'));
        $this->assertFalse($this->setting('demo_content'));
        $this->assertDatabaseCount('users', 1);
        foreach (['threads', 'posts', 'shouts', 'import_runs', 'import_maps'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_demo_is_rejected_for_migration_mode_before_any_installation_writes(): void
    {
        $this->postJson('/install', $this->input() + ['locale' => 'en', 'setup_mode' => 'migrate', 'demo' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['demo'])
            ->assertJsonPath('errors.demo.0', 'Turn off the sample community before preparing a migration.');
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('categories', 0);
        $this->assertNull($this->setting('setup_complete'));
        $this->assertFileExists($this->tempStorage.'/install-token');
    }

    public function test_unknown_installation_language_and_mode_are_rejected(): void
    {
        $this->postJson('/install', $this->input() + ['locale' => '../../bad', 'setup_mode' => 'wipe'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['locale', 'setup_mode']);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_nonempty_categories_table_cannot_be_claimed_without_users(): void
    {
        // A damaged/foreign database may contain no accounts but still own
        // valuable forum content. Refuse before Artisan touches its schema.
        DB::table('categories')->insert([
            'name' => 'Existing data', 'slug' => 'existing-data',
            'description' => 'Keep this content', 'icon' => 'folder',
            'color' => 'violet', 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::shouldReceive('call')->never();
        $this->post('/install', $this->input())->assertStatus(409);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseHas('categories', ['slug' => 'existing-data']);
        $this->assertNull($this->setting('setup_complete'));
        $this->assertFileExists($this->tempStorage.'/install-token');
    }

    public function test_unknown_empty_legacy_table_blocks_installer_migrations(): void
    {
        // Unknown tables are rejected even when empty: installing new tables
        // in somebody else's database is not a supported "fresh install".
        \Illuminate\Support\Facades\Schema::create('mybb_users', static function ($table) {
            $table->increments('uid');
        });
        try {
            Artisan::shouldReceive('call')->never();
            $this->post('/install', $this->input())->assertStatus(409);
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('mybb_users'));
            $this->assertDatabaseCount('users', 0);
        } finally {
            \Illuminate\Support\Facades\Schema::dropIfExists('mybb_users');
        }
    }

    public function test_occupied_database_cannot_be_claimed_or_reset_by_fresh_installation(): void
    {
        $existing = User::factory()->create(['email' => 'existing@example.test']);
        // Reject before the migration command, not merely in the post-migrate
        // transaction (which would already have modified an occupied schema).
        Artisan::shouldReceive('call')->never();
        $this->post('/install', $this->input() + ['setup_mode' => 'fresh'])->assertStatus(409);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $existing->id, 'email' => 'existing@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
        $this->assertDatabaseCount('categories', 0);
        $this->assertNull($this->setting('setup_complete'));
        $this->assertFileExists($this->tempStorage.'/install-token');
    }
}
