<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ForumService;
use App\Services\MybbImporter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class MybbImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('m', 32)),
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
    }

    private function fixture(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/mybb-small.json')), true, 64, JSON_THROW_ON_ERROR);
    }

    private function importer(): MybbImporter
    {
        return app(MybbImporter::class);
    }

    private function assertRejected(array $data): void
    {
        try {
            $this->importer()->import($data);
            $this->fail('The invalid migration should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    public function test_preview_reports_scope_without_writing_data_or_exposing_hashes(): void
    {
        $report = $this->importer()->preview($this->fixture());
        $this->assertSame('ready', $report['status']);
        $this->assertSame(['users' => 2, 'forums' => 2, 'threads' => 1, 'posts' => 2], $report['counts']);
        $this->assertTrue($report['private_quarantine']);
        $this->assertGreaterThanOrEqual(5, count($report['warnings']));
        $this->assertStringNotContainsString($this->fixture()['users'][0]['password'], json_encode($report));
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('import_runs', 0);
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_import_preserves_links_dates_bbcode_and_keeps_every_category_private(): void
    {
        $report = $this->importer()->import($this->fixture());
        $this->assertSame('completed', $report['status']);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('categories', 2);
        $this->assertDatabaseCount('threads', 1);
        $this->assertDatabaseCount('posts', 2);
        $this->assertDatabaseCount('import_maps', 7);
        $this->assertDatabaseCount('import_runs', 1);
        $this->assertSame(0, DB::table('categories')->where('is_private', false)->count());
        $author = DB::table('users')->where('email', 'archive@example.test')->first();
        $this->assertSame('member', $author->role); // MyBB source group 4 must not elevate access.
        $this->assertSame('2021-01-01 00:00:00', $author->created_at);
        $thread = DB::table('threads')->first();
        $this->assertSame($author->id, $thread->user_id);
        $child = DB::table('categories')->where('name', 'Pomoc techniczna')->first();
        $parent = DB::table('categories')->where('name', 'Archiwum społeczności')->first();
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame($child->id, $thread->category_id);
        $post = DB::table('posts')->orderBy('id')->first();
        $this->assertSame($thread->id, $post->thread_id);
        $this->assertSame('bbcode', $post->body_format);
        $this->assertSame($this->fixture()['posts'][0]['message'], $post->body);
        $this->assertFalse(ForumService::canSeeCategory($child, null));
        $this->assertFalse(ForumService::canSeeCategory($child, User::find($author->id)));
    }

    public function test_import_inserts_posts_in_numeric_mybb_id_order_even_when_archive_is_reversed(): void
    {
        $data = $this->fixture();
        // The starter is MyBB pid=2, reply pid=10. Lexical key sort
        // incorrectly inserts reply before starter, changing thread excerpts
        // and making the first real post look deletable.
        $data['posts'][0]['pid'] = 2;
        $data['posts'][1]['pid'] = 10;
        $data['posts'] = array_reverse($data['posts']);

        $this->importer()->import($data);
        $posts = DB::table('posts')->orderBy('id')->get();
        $this->assertCount(2, $posts);
        $this->assertSame($data['posts'][1]['message'], $posts[0]->body);
        $this->assertSame($data['posts'][0]['message'], $posts[1]->body);

        // Changing physical insertion order must not change the existing
        // fingerprint or break repeat-import recognition.
        $again = $this->importer()->import($data);
        $this->assertSame('already_imported', $again['status']);
        $this->assertDatabaseCount('posts', 2);
    }

    public function test_reimport_is_idempotent_even_when_export_timestamp_changes(): void
    {
        $data = $this->fixture();
        $first = $this->importer()->import($data);
        $data['exported_at'] = '2026-11-01T00:00:00Z';
        $data['users'] = array_reverse($data['users']);
        $again = $this->importer()->import($data);
        $this->assertSame('already_imported', $again['status']);
        $this->assertSame($first['fingerprint'], $again['fingerprint']);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('posts', 2);
        $this->assertDatabaseCount('import_runs', 1);
        $this->assertDatabaseCount('audit_logs', 1);
    }

    public function test_changed_export_for_existing_source_is_rejected_without_duplicates(): void
    {
        $data = $this->fixture();
        $this->importer()->import($data);
        $data['posts'][0]['message'] .= ' changed';
        $this->assertRejected($data);
        $this->assertDatabaseCount('posts', 2);
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('import_runs', 1);
        $this->assertStringNotContainsString(' changed', DB::table('posts')->orderBy('id')->value('body'));
    }

    public function test_case_insensitive_existing_email_conflicts_never_merge_or_partially_import(): void
    {
        User::factory()->create(['email' => 'ARCHIVE@example.test']);
        $this->assertRejected($this->fixture());
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('categories', 0);
        $this->assertDatabaseCount('import_runs', 0);
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_unknown_author_causes_full_preflight_rejection(): void
    {
        $data = $this->fixture();
        $data['posts'][1]['uid'] = 99999;
        $this->assertRejected($data);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_missing_thread_and_inconsistent_forum_are_rejected(): void
    {
        $data = $this->fixture();
        $data['posts'][1]['tid'] = 999;
        $this->assertRejected($data);
        $data = $this->fixture();
        $data['posts'][1]['fid'] = 3;
        $this->assertRejected($data);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_cyclic_forum_hierarchy_is_rejected_before_writes(): void
    {
        $data = $this->fixture();
        $data['forums'][0]['pid'] = 4;
        $this->assertRejected($data);
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_duplicate_ids_and_duplicate_emails_are_rejected(): void
    {
        $data = $this->fixture();
        $data['users'][1]['uid'] = 7;
        $this->assertRejected($data);
        $data = $this->fixture();
        $data['users'][1]['email'] = 'ARCHIVE@example.test';
        $this->assertRejected($data);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_database_failure_rolls_back_users_categories_posts_and_bookkeeping(): void
    {
        DB::listen(function (QueryExecuted $query) {
            if (str_starts_with(strtolower($query->sql), 'insert into "posts"')) {
                throw new RuntimeException('Injected storage failure');
            }
        });
        try {
            $this->importer()->import($this->fixture());
            $this->fail('Expected injected failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Injected storage failure', $exception->getMessage());
        }
        foreach (['users', 'categories', 'threads', 'posts', 'import_maps', 'import_runs', 'settings', 'audit_logs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_unsupported_hashes_and_source_bans_stay_locked(): void
    {
        $data = $this->fixture();
        $data['users'][0]['password'] = '$some-custom-unsupported-hash';
        $data['users'][1]['is_banned'] = 1;
        $report = $this->importer()->import($data);
        $this->assertSame(1, $report['unsupported_passwords']);
        $this->assertSame(2, DB::table('users')->where('is_banned', true)->count());
        $this->assertNull(DB::table('users')->where('email', 'archive@example.test')->value('legacy_password_hash'));
        $this->assertNotNull(DB::table('users')->where('email', 'reader@example.test')->value('legacy_password_hash'));
    }

    public function test_guest_authorship_gets_a_non_login_archive_account(): void
    {
        $data = $this->fixture();
        $data['posts'][1]['uid'] = 0;
        $data['posts'][1]['username'] = 'Gość z archiwum';
        $report = $this->importer()->import($data);
        $this->assertSame(1, $report['guest_accounts']);
        $guest = DB::table('users')->where('name', 'Gość z archiwum')->first();
        $this->assertTrue((bool) $guest->is_banned);
        $this->assertNull($guest->legacy_password_hash);
        $this->assertStringEndsWith('@imports.invalid', $guest->email);
        $this->assertSame($guest->id, DB::table('posts')->orderByDesc('id')->value('user_id'));
    }

    public function test_unapproved_content_is_not_silently_published(): void
    {
        $data = $this->fixture();
        $data['posts'][1]['visible'] = 0;
        $this->assertRejected($data);
        $this->assertDatabaseCount('posts', 0);
    }

    public function test_incompatible_format_version_urls_and_oversized_fields_fail_validation(): void
    {
        $data = $this->fixture();
        $data['format'] = 'sql';
        $this->assertRejected($data);
        $data = $this->fixture();
        $data['mybb_version'] = '1.9.0';
        $this->assertRejected($data);
        $data = $this->fixture();
        $data['source_url'] = 'https://user:secret@example.test';
        $this->assertRejected($data);
        $data = $this->fixture();
        $data['threads'][0]['subject'] = str_repeat('a', 181);
        $this->assertRejected($data);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_invalid_json_is_never_treated_as_sql(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'veyra-invalid-');
        file_put_contents($file, 'DROP TABLE users;');
        try {
            $this->importer()->readFile($file);
            $this->fail('An SQL dump must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        } finally {
            unlink($file);
        }
        $this->assertDatabaseCount('users', 0);
    }

    public function test_legacy_fixture_hash_matches_the_official_mybb_algorithm(): void
    {
        $user = $this->fixture()['users'][0];
        $this->assertTrue(hash_equals($user['password'], md5(md5($user['salt']).md5('Migration-test-2026!'))));
        $this->assertFalse(hash_equals($user['password'], md5(md5($user['salt']).md5('incorrect'))));
    }

    public function test_real_login_upgrades_imported_password_to_argon2id_and_clears_legacy_fields(): void
    {
        $this->importer()->import($this->fixture());
        ForumService::setSetting('setup_complete', true);
        $user = User::where('email', 'archive@example.test')->firstOrFail();
        $oldPassword = $user->password;
        $legacyHash = $user->legacy_password_hash;

        $response = $this->postJson('/api/auth/login', [
            'email' => 'archive@example.test', 'password' => 'Migration-test-2026!',
        ])->assertOk()->assertJsonPath('user.id', $user->id);

        $user->refresh();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('argon2id', password_get_info($user->password)['algoName']);
        $this->assertNotSame($oldPassword, $user->password);
        $this->assertTrue(Hash::check('Migration-test-2026!', $user->password));
        $this->assertNull($user->legacy_password_hash);
        $this->assertNull($user->legacy_password_salt);
        $this->assertStringNotContainsString($legacyHash, $response->getContent());
        $this->assertStringNotContainsString('legacy_password', $response->getContent());
    }

    public function test_wrong_legacy_password_does_not_change_credentials_or_authenticate(): void
    {
        $this->importer()->import($this->fixture());
        ForumService::setSetting('setup_complete', true);
        $user = User::where('email', 'archive@example.test')->firstOrFail();
        $before = [$user->password, $user->legacy_password_hash, $user->legacy_password_salt];

        $this->postJson('/api/auth/login', [
            'email' => 'archive@example.test', 'password' => 'Wrong-archive-password!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $user->refresh();
        $this->assertGuest();
        $this->assertSame($before, [$user->password, $user->legacy_password_hash, $user->legacy_password_salt]);
    }

    public function test_imported_account_with_valid_email_longer_than_190_characters_can_log_in(): void
    {
        $data = $this->fixture();
        $email = str_repeat('u', 64).'@'.str_repeat('a', 63).'.'.str_repeat('b', 60).'.example.test';
        $this->assertGreaterThan(190, strlen($email));
        $this->assertLessThanOrEqual(254, strlen($email));
        $data['users'][0]['email'] = $email;
        $this->importer()->import($data);
        ForumService::setSetting('setup_complete', true);

        $this->postJson('/api/auth/login', [
            'email' => strtoupper($email), 'password' => 'Migration-test-2026!',
        ])->assertOk()->assertJsonPath('user.email', $email);
        $user = User::where('email', $email)->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('argon2id', password_get_info($user->password)['algoName']);
        $this->assertNull($user->legacy_password_hash);
        $this->assertNull($user->legacy_password_salt);
    }

}
