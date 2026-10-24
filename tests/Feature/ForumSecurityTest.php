<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cross-boundary tests for the public API. These fixtures deliberately bypass
 * controllers so permission checks are tested against pre-existing data.
 */
class ForumSecurityTest extends TestCase
{
    use RefreshDatabase;

    private int $userSequence = 0;
    private int $categorySequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        $this->setting('setup_complete', true);
        $this->setting('registration_open', true);
        foreach (['shoutbox', 'rankings', 'welcome'] as $key) {
            DB::table('plugin_states')->insert([
                'key' => $key, 'enabled' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function test_guest_bootstrap_and_search_do_not_expose_private_content_or_password_material(): void
    {
        $author = $this->user('admin');
        $author->forceFill([
            'legacy_password_hash' => str_repeat('a', 32),
            'legacy_password_salt' => 'secretSalt',
        ])->save();
        $public = $this->category(false, 'Public help');
        $private = $this->category(true, 'Private staff room');
        [$visible] = $this->thread($author, $public, 'Public discussion');
        [$hidden] = $this->thread($author, $private, 'Confidential deployment secret');

        $bootstrap = $this->getJson('/api/bootstrap')->assertOk();
        $ids = array_column($bootstrap->json('categories'), 'id');
        $this->assertContains($public, $ids);
        $this->assertNotContains($private, $ids);
        $this->assertStringNotContainsString('Private staff room', $bootstrap->getContent());
        $bootstrap->assertJsonPath('stats.threads', 1)->assertJsonPath('stats.posts', 1);
        $this->assertNoCredentials($bootstrap);

        $listing = $this->getJson('/api/threads')->assertOk();
        $ids = array_column($listing->json('data'), 'id');
        $this->assertContains($visible, $ids);
        $this->assertNotContains($hidden, $ids);
        $this->assertNoCredentials($listing);
        $this->assertStringNotContainsString($author->email, $listing->getContent());

        $this->getJson('/api/threads?q=Confidential')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/threads?category='.$private)->assertJsonCount(0, 'data');
        $this->assertDenied($this->getJson('/api/threads/'.$hidden));
    }

    public function test_search_treats_like_wildcards_as_literal_without_leaking_private_topics(): void
    {
        $author = $this->user();
        $public = $this->category();
        $private = $this->category(true);
        [$percent] = $this->thread($author, $public, '100% completed');
        [$unescapedZero] = $this->thread($author, $public, '1000 completed');
        [$underscore] = $this->thread($author, $public, 'nick_name change');
        $this->thread($author, $public, 'nickXname change');
        [$escape] = $this->thread($author, $public, 'hello!world');
        $this->thread($author, $public, 'helloXworld');
        [$body] = $this->thread($author, $public, 'Generic discussion', false, 'body*mark in this reply');
        $this->thread($author, $private, '100% private', false, 'body*mark secret');

        foreach ([
            '100%' => [$percent],
            '0' => [$percent, $unescapedZero],
            'nick_' => [$underscore],
            'hello!' => [$escape],
            'body*mark' => [$body],
            "' OR 1=1 --" => [],
        ] as $term => $expected) {
            $response = $this->getJson('/api/threads?q='.rawurlencode($term))->assertOk();
            // Search membership is the contract here; equal timestamps can reorder IDs.
            $this->assertEqualsCanonicalizing($expected, array_column($response->json('data'), 'id'), $term);
            $this->assertSame(count($expected), $response->json('total'), $term);
        }
    }

    /**
     * Native PHPUnit/PDO qualification gate for the batched 40-post response.
     * This test is intentionally not counted PASS on a PHP without pdo_sqlite.
     */
    public function test_thread_page_batches_post_relations_without_changing_payload(): void
    {
        $author = $this->user();
        $viewer = $this->user();
        [$thread, $first] = $this->thread($author, $this->category());
        for ($i = 2; $i <= 42; $i++) {
            $this->makePost($author, $thread, 'Post '.$i);
        }
        DB::table('reactions')->insert([
            'post_id' => $first, 'user_id' => $viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('attachments')->insert([
            'post_id' => $first, 'user_id' => $author->id,
            'path' => 'attachments/thread-test.txt', 'name' => 'thread-test.txt',
            'mime' => 'text/plain', 'size' => 17,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($viewer);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $response = $this->getJson('/api/threads/'.$thread.'?page=1')->assertOk()
                ->assertJsonCount(40, 'posts')->assertJsonPath('total_posts', 42)
                ->assertJsonPath('last_page', 2);
            $reads = count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
        $this->assertLessThan(30, $reads, 'Topic page performed per-post N+1 reads');
        $firstRow = $response->json('posts.0');
        $this->assertSame($first, $firstRow['id']);
        $this->assertTrue($firstRow['reacted']);
        $this->assertSame(1, $firstRow['reactions_count']);
        $this->assertSame($author->id, $firstRow['author']['id']);
        $this->assertSame('thread-test.txt', $firstRow['attachments'][0]['name']);
        $this->assertArrayNotHasKey('post_id', $firstRow['attachments'][0]);
        $this->assertFalse($response->json('posts.1.reacted'));
        $this->getJson('/api/threads/'.$thread.'?page=2')->assertOk()
            ->assertJsonCount(2, 'posts')->assertJsonPath('total_posts', 42);
    }

    public function test_public_child_category_inherits_private_ancestor_restriction(): void
    {
        $owner = $this->user('admin');
        $parent = $this->category(true, 'Staff-only parent');
        $child = $this->category(false, 'Public-looking child');
        DB::table('categories')->where('id', $child)->update(['parent_id' => $parent]);
        [$thread] = $this->thread($owner, $child, 'Inherited secret');

        $bootstrap = $this->getJson('/api/bootstrap')->assertOk();
        $ids = array_column($bootstrap->json('categories'), 'id');
        $this->assertNotContains($child, $ids);
        $this->getJson('/api/threads?q=Inherited')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDenied($this->getJson('/api/threads/'.$thread));
    }

    /** Native Laravel HTTP/PDO qualification, not counted green on incomplete PHP. */
    public function test_demoted_moderator_with_stale_session_cannot_write_to_private_forum(): void
    {
        $owner = $this->user('admin');
        $moderator = $this->user('moderator');
        $category = $this->category(true, 'Restricted board');
        [$thread, $post] = $this->thread($owner, $category, 'Secret project');

        // Capture an authenticated moderator object, then revoke the actual
        // role. Earlier create/reply/bookmark/reaction preflights trusted this
        // cached role even though their SQL write guard only checked ban.
        $this->actingAs($moderator);
        DB::table('users')->where('id', $moderator->id)->update(['role' => 'member']);
        foreach ([
            ['POST', '/api/threads/'.$thread.'/posts', ['body' => 'Unauthorized secret reply']],
            ['POST', '/api/threads', ['category_id' => $category,
                'title' => 'Unauthorized secret topic', 'body' => 'Private data']],
            ['POST', '/api/threads/'.$thread.'/bookmark', []],
            ['POST', '/api/posts/'.$post.'/reaction', []],
        ] as [$method, $url, $data]) {
            $moderator->role = 'moderator'; // Deliberately stale, each time.
            $this->actingAs($moderator);
            $response = $this->json($method, $url, $data);
            $this->assertContains($response->status(), [403, 404], $url.' must reject stale staff privilege');
        }
        $this->assertDatabaseCount('threads', 1);
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('bookmarks', 0);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_member_cannot_read_reply_react_bookmark_or_upload_into_private_category(): void
    {
        $staff = $this->user('admin');
        $member = $this->user();
        $private = $this->category(true);
        [$thread, $post] = $this->thread($staff, $private, 'Private evidence');
        $this->actingAs($member);

        $this->assertDenied($this->getJson('/api/threads/'.$thread));
        $this->assertDenied($this->postJson('/api/threads/'.$thread.'/posts', ['body' => 'Unauthorized reply']));
        $this->assertDenied($this->postJson('/api/posts/'.$post.'/reaction'));
        $this->assertDenied($this->postJson('/api/threads/'.$thread.'/bookmark'));
        $this->assertDenied($this->postJson('/api/threads', [
            'category_id' => $private, 'title' => 'Unauthorized topic', 'body' => 'Should not be written.',
        ]));
        $this->assertDenied($this->post('/api/posts/'.$post.'/attachments', [
            'file' => UploadedFile::fake()->createWithContent('note.txt', 'private attachment'),
        ], ['Accept' => 'application/json']));

        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseCount('reactions', 0);
        $this->assertDatabaseCount('bookmarks', 0);
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_moderator_can_read_private_threads_but_cannot_administer_settings(): void
    {
        $owner = $this->user('admin');
        $moderator = $this->user('moderator');
        [$thread] = $this->thread($owner, $this->category(true));
        $this->actingAs($moderator)->getJson('/api/threads/'.$thread)->assertOk();
        $this->getJson('/api/admin')->assertForbidden();
        $this->patchJson('/api/admin/settings', ['site_name' => 'Unauthorized'])->assertForbidden();
    }

    public function test_thread_deletion_rejects_nonstaff_banned_and_demoted_accounts(): void
    {
        $author = $this->user();
        $moderator = $this->user('moderator');
        [$thread, $post] = $this->thread($author, $this->category(), 'Thread deletion guard');

        $this->actingAs($author)->deleteJson('/api/threads/'.$thread)->assertForbidden();
        $this->assertDatabaseHas('threads', ['id' => $thread]);

        $moderator->forceFill(['is_banned' => true])->save();
        $this->actingAs($moderator)->deleteJson('/api/threads/'.$thread)->assertForbidden();
        $this->assertDatabaseHas('threads', ['id' => $thread]);

        $moderator->forceFill(['is_banned' => false, 'role' => 'member'])->save();
        $this->actingAs($moderator)->deleteJson('/api/threads/'.$thread)->assertForbidden();
        $this->assertDatabaseHas('posts', ['id' => $post]);

        $moderator->forceFill(['role' => 'moderator'])->save();
        $this->actingAs($moderator)->deleteJson('/api/threads/'.$thread)->assertOk();
        $this->assertDatabaseMissing('threads', ['id' => $thread]);
        $this->assertDatabaseMissing('posts', ['id' => $post]);
    }

    public function test_all_admin_mutations_require_an_admin(): void
    {
        $member = $this->user();
        $other = $this->user();
        $this->actingAs($member);

        $this->getJson('/api/admin')->assertForbidden();
        $this->patchJson('/api/admin/settings', ['site_name' => 'Hijacked'])->assertForbidden();
        $this->patchJson('/api/admin/plugins/shoutbox', ['enabled' => false])->assertForbidden();
        $this->patchJson('/api/admin/users/'.$other->id, ['is_banned' => true])->assertForbidden();
        $this->postJson('/api/admin/imports/mybb/preview')->assertForbidden();
        $this->postJson('/api/admin/imports/mybb')->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $other->id, 'is_banned' => false]);
    }

    public function test_registration_ignores_attempted_role_ban_and_legacy_hash_injection(): void
    {
        $password = 'UniqueTestingPassword!2026';
        $response = $this->postJson('/api/auth/register', [
            'name' => 'New community member', 'email' => 'new.member@example.test',
            'password' => $password, 'password_confirmation' => $password,
            'role' => 'admin', 'is_banned' => false,
            'legacy_password_hash' => str_repeat('b', 32), 'legacy_password_salt' => 'attack',
        ])->assertCreated();

        $newUser = User::where('email', 'new.member@example.test')->firstOrFail();
        $this->assertSame('member', $newUser->role);
        $this->assertTrue(Hash::check($password, $newUser->password));
        $this->assertNull($newUser->legacy_password_hash);
        $this->assertNull($newUser->legacy_password_salt);
        $this->assertNoCredentials($response);
        $this->getJson('/api/admin')->assertForbidden();
    }

    public function test_closed_registration_cannot_be_overridden_by_request_fields(): void
    {
        $this->setting('registration_open', false);
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Uninvited member', 'email' => 'closed@example.test',
            'password' => 'StrongTestPassword!2026', 'password_confirmation' => 'StrongTestPassword!2026',
            'registration_open' => true,
        ]);
        $this->assertDenied($response);
        $this->assertDatabaseMissing('users', ['email' => 'closed@example.test']);
    }

    public function test_long_passwords_are_rejected_or_verified_without_silent_truncation(): void
    {
        $prefix = str_repeat('A', 72);
        $password = $prefix.'Original!';
        $registered = $this->postJson('/api/auth/register', [
            'name' => 'Long password member', 'email' => 'long.password@example.test',
            'password' => $password, 'password_confirmation' => $password,
        ]);

        if ($registered->status() === 422) {
            $this->assertDatabaseMissing('users', ['email' => 'long.password@example.test']);
            return;
        }

        $registered->assertCreated();
        $this->postJson('/api/auth/logout')->assertOk();
        $this->postJson('/api/auth/login', [
            'email' => 'long.password@example.test', 'password' => $prefix.'Different!',
        ])->assertUnprocessable();
        $this->assertGuest();
        $this->postJson('/api/auth/login', [
            'email' => 'long.password@example.test', 'password' => $password,
        ])->assertOk();
    }

    public function test_login_logout_and_wrong_password_have_correct_effects(): void
    {
        $user = $this->user();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable();
        $this->assertGuest();
        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'SafeForumPass!2026'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->assertNoCredentials($login);
        $this->assertAuthenticatedAs($user);
        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertGuest();
        $this->postJson('/api/shouts', ['body' => 'No session'])->assertUnauthorized();
    }

    public function test_array_email_input_is_a_validation_error_instead_of_a_server_error(): void
    {
        $this->postJson('/api/auth/login', [
            'email' => ['unexpected', 'array'], 'password' => 'SafeForumPass!2026',
        ])->assertUnprocessable();
        $this->postJson('/api/auth/register', [
            'name' => 'Invalid input', 'email' => ['unexpected', 'array'],
            'password' => 'SafeForumPass!2026', 'password_confirmation' => 'SafeForumPass!2026',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_banned_user_cannot_login_or_write_using_an_existing_session(): void
    {
        $user = $this->user();
        $user->forceFill(['is_banned' => true])->save();
        $category = $this->category();

        $login = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'SafeForumPass!2026']);
        $this->assertContains($login->status(), [403, 422]);
        $this->assertGuest();

        $this->actingAs($user)->postJson('/api/threads', [
            'category_id' => $category, 'title' => 'Blocked topic', 'body' => 'Should not appear.',
        ])->assertForbidden();
        $this->actingAs($user)->postJson('/api/shouts', ['body' => 'Blocked shout'])->assertForbidden();
        // A ban must cover all existing-session write paths, including
        // changes to the public display name, biography and PM opt-out.
        $this->actingAs($user)->patchJson('/api/profile', [
            'name' => 'Renamed after ban',
            'bio' => 'Edited while blocked',
            'allow_private_messages' => false,
        ])->assertForbidden()->assertJsonPath('message', __('server.account_blocked'));
        $fresh = $user->fresh();
        $this->assertSame($user->name, $fresh->name);
        $this->assertSame($user->bio, $fresh->bio);
        $this->assertTrue((bool) $fresh->allow_private_messages);
        $this->assertDatabaseCount('threads', 0);
        $this->assertDatabaseCount('shouts', 0);
    }

    public function test_active_member_can_update_profile_and_pm_preference(): void
    {
        $user = $this->user();
        $this->actingAs($user)->patchJson('/api/profile', [
            'name' => 'A new public name',
            'bio' => 'Edited by the owner',
            'allow_private_messages' => false,
        ])->assertOk()->assertJsonPath('user.name', 'A new public name')
            ->assertJsonPath('user.allow_private_messages', false);
        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'A new public name',
            'bio' => 'Edited by the owner',
            'allow_private_messages' => false,
        ]);
    }

    public function test_guests_cannot_create_content(): void
    {
        $owner = $this->user();
        $category = $this->category();
        [$thread] = $this->thread($owner, $category);
        $this->postJson('/api/threads', [
            'category_id' => $category, 'title' => 'Guest attack', 'body' => 'Rejected',
        ])->assertUnauthorized();
        $this->postJson('/api/threads/'.$thread.'/posts', ['body' => 'Guest reply'])->assertUnauthorized();
        $this->postJson('/api/shouts', ['body' => 'Guest shout'])->assertUnauthorized();
    }

    public function test_creating_a_thread_and_reply_preserves_content_and_author_ownership(): void
    {
        $author = $this->user();
        $replyAuthor = $this->user();
        $category = $this->category();
        $created = $this->actingAs($author)->postJson('/api/threads', [
            'category_id' => $category, 'title' => 'Zażółć gęślą jaźń — discussion',
            'body' => "## Welcome\nA useful first message.", 'user_id' => $replyAuthor->id,
        ])->assertCreated();
        $id = $created->json('thread.id');
        $this->assertIsInt($id);
        $this->assertDatabaseHas('threads', ['id' => $id, 'user_id' => $author->id]);
        $this->assertDatabaseHas('posts', ['thread_id' => $id, 'user_id' => $author->id]);

        $this->actingAs($replyAuthor)->postJson('/api/threads/'.$id.'/posts', [
            'body' => 'A different member replies.', 'user_id' => $author->id,
        ])->assertCreated()->assertJsonPath('post.author.id', $replyAuthor->id);
        $details = $this->getJson('/api/threads/'.$id)->assertOk();
        $details->assertJsonCount(2, 'posts');
        $this->assertStringContainsString('Zażółć', $details->json('thread.title'));
    }

    /**
     * Full Laravel/PDO/HTTP gate for dev.24; cannot run on the current host.
     * Mimic a ban after request authentication by changing the DB row while
     * holding the original User instance, then check the INSERT predicate.
     */
    public function test_thread_creation_rechecks_database_ban_when_session_user_is_stale(): void
    {
        $author = $this->user();
        $category = $this->category();
        $this->actingAs($author)->postJson('/api/threads', [
            'category_id' => $category, 'title' => 'Active thread works',
            'body' => 'Original reply',
        ])->assertCreated();
        $this->assertDatabaseCount('threads', 1);
        $this->assertDatabaseCount('posts', 1);

        // The actor's request object still has the original is_banned=false.
        $this->assertFalse((bool) $author->is_banned);
        DB::table('users')->where('id', $author->id)->update(['is_banned' => true]);
        $eligible = \App\Services\ThreadCreateQuery::eligible(
            (int) $author->id, $category, 'Denied stale user thread',
            'denied-stale-user-thread', '2026-10-23 15:00:00'
        );
        $inserted = DB::table('threads')->insertUsing(
            ['category_id','user_id','title','slug','created_at','updated_at'], $eligible
        );
        $this->assertSame(0, $inserted);
        $this->assertDatabaseCount('threads', 1);
        $this->assertDatabaseCount('posts', 1);
    }

    /**
     * HTTP/PDO qualification pending: ban, lock, and demotion committed after
     * the request's original preflight must prevent a reply INSERT.
     */
    public function test_reply_insert_rechecks_current_ban_lock_and_moderator_role(): void
    {
        $author = $this->user();
        $moderator = $this->user('moderator');
        [$thread] = $this->thread($author, $this->category(), 'Protected reply thread');
        $columns = ['thread_id','user_id','body','body_format','created_at','updated_at'];
        $attempt = static function (int $id, int $actor) use ($columns): int {
            return DB::table('posts')->insertUsing($columns,
                \App\Services\ThreadReplyQuery::eligible($actor, $id, 'Post preflight text', '2026-10-23 15:00:00')
            );
        };

        DB::table('users')->where('id',$author->id)->update(['is_banned' => true]);
        $this->assertSame(0, $attempt($thread,$author->id));
        DB::table('users')->where('id',$author->id)->update(['is_banned' => false]);
        DB::table('threads')->where('id',$thread)->update(['is_locked' => true]);
        $this->assertSame(0, $attempt($thread,$author->id));
        $this->assertSame(1, $attempt($thread,$moderator->id));
        DB::table('users')->where('id',$moderator->id)->update(['role' => 'member']);
        $this->assertSame(0, $attempt($thread,$moderator->id));
        DB::table('threads')->where('id',$thread)->update(['is_locked' => false]);
        $this->assertSame(1, $attempt($thread,$author->id));
        $this->assertDatabaseCount('posts',3); // original thread post + two allowed replies
    }

    public function test_locked_thread_rejects_member_replies_and_member_cannot_unlock_it(): void
    {
        $author = $this->user();
        [$thread] = $this->thread($author, $this->category(), 'Locked conversation', true);
        $this->actingAs($author);
        $this->assertDenied($this->postJson('/api/threads/'.$thread.'/posts', ['body' => 'A forbidden reply.']));
        $this->patchJson('/api/threads/'.$thread, ['is_locked' => false])->assertForbidden();
        $this->assertDatabaseCount('posts', 1);
        $this->assertDatabaseHas('threads', ['id' => $thread, 'is_locked' => true]);
    }

    public function test_member_cannot_edit_or_delete_another_members_post(): void
    {
        $owner = $this->user();
        $attacker = $this->user();
        [$thread] = $this->thread($owner, $this->category());
        $reply = $this->makePost($owner, $thread, 'Original reply body.');
        $this->actingAs($attacker)->patchJson('/api/posts/'.$reply, ['body' => 'Forged content'])->assertForbidden();
        $this->deleteJson('/api/posts/'.$reply)->assertForbidden();
        $this->assertDatabaseHas('posts', ['id' => $reply, 'body' => 'Original reply body.']);
    }

    public function test_author_can_edit_own_reply_but_cannot_mass_assign_author_or_thread(): void
    {
        $author = $this->user();
        $other = $this->user();
        [$thread] = $this->thread($author, $this->category());
        $reply = $this->makePost($author, $thread, 'Original');
        $this->actingAs($author)->patchJson('/api/posts/'.$reply, [
            'body' => 'Improved reply', 'user_id' => $other->id, 'thread_id' => 999999,
        ])->assertOk();
        $this->assertDatabaseHas('posts', [
            'id' => $reply, 'body' => 'Improved reply', 'user_id' => $author->id, 'thread_id' => $thread,
        ]);
    }

    public function test_reaction_toggle_is_unique_and_own_reactions_are_rejected(): void
    {
        $author = $this->user();
        $reader = $this->user();
        [, $post] = $this->thread($author, $this->category());
        $own = $this->actingAs($author)->postJson('/api/posts/'.$post.'/reaction');
        $this->assertContains($own->status(), [403, 422]);
        $this->actingAs($reader)->postJson('/api/posts/'.$post.'/reaction')
            ->assertOk()->assertJsonPath('reacted', true)->assertJsonPath('reactions_count', 1);
        $this->postJson('/api/posts/'.$post.'/reaction')
            ->assertOk()->assertJsonPath('reacted', false)->assertJsonPath('reactions_count', 0);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_bookmarks_are_user_specific_and_hidden_after_category_becomes_private(): void
    {
        $author = $this->user();
        $reader = $this->user();
        $other = $this->user();
        $category = $this->category();
        [$thread] = $this->thread($author, $category);
        $this->actingAs($reader)->postJson('/api/threads/'.$thread.'/bookmark')
            ->assertOk()->assertJsonPath('bookmarked', true);
        $this->getJson('/api/threads?bookmarked=1')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson('/api/threads?bookmarked=1')->assertOk()->assertJsonCount(0, 'data');
        DB::table('categories')->where('id', $category)->update(['is_private' => true]);
        $this->actingAs($reader)->getJson('/api/threads?bookmarked=1')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_rendered_markdown_rejects_script_html_event_handlers_and_javascript_urls(): void
    {
        $author = $this->user();
        $body = '<script>alert(1)</script><img src=x onerror=alert(2)>'
            ."\n\n[click](javascript:alert%283%29)\n\n**Useful text**";
        [$thread, $post] = $this->thread($author, $this->category(), 'Safe rendering', false, $body);
        $response = $this->getJson('/api/threads/'.$thread)->assertOk();
        $rendered = $response->json('posts.0.body_html');
        $this->assertIsString($rendered);
        $this->assertStringNotContainsString('<script', strtolower($rendered));
        $this->assertStringNotContainsString('onerror=', strtolower($rendered));
        $this->assertStringNotContainsString('href="javascript:', strtolower($rendered));
        $this->assertStringContainsString('Useful text', $rendered);
        $this->assertDatabaseHas('posts', ['id' => $post, 'body' => $body]);
    }

    public function test_disabled_shoutbox_cannot_be_used_through_api(): void
    {
        $admin = $this->user('admin');
        $member = $this->user();
        $this->actingAs($admin)->patchJson('/api/admin/plugins/shoutbox', ['enabled' => false])->assertOk();
        $this->actingAs($member);
        $read = $this->getJson('/api/shouts');
        if ($read->status() === 200) {
            $read->assertJsonPath('enabled', false)->assertJsonCount(0, 'data');
        } else {
            $this->assertDenied($read);
        }
        $this->assertDenied($this->postJson('/api/shouts', ['body' => 'Cannot bypass disabled plugin.']));
        $this->assertDatabaseCount('shouts', 0);
    }

    /** PDO/HTTP qualification gate; not executed on hosts lacking pdo_sqlite. */
    public function test_shout_history_sql_is_authorized_by_live_plugin_state(): void
    {
        $author = $this->user();
        DB::table('shouts')->insert([
            'user_id' => $author->id, 'body' => 'Previously public shout',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertCount(1, \App\Services\ShoutboxReadQuery::eligible()->get());
        DB::table('plugin_states')->where('key', 'shoutbox')->update(['enabled' => false]);
        $this->assertCount(0, \App\Services\ShoutboxReadQuery::eligible()->get());
        $this->assertCount(0, \App\Services\ShoutboxReadQuery::eligible()->where('id', '>', 0)->get());
        $this->getJson('/api/shouts')->assertNotFound();
        DB::table('plugin_states')->where('key', 'shoutbox')->update(['enabled' => true]);
        $this->assertCount(1, \App\Services\ShoutboxReadQuery::eligible()->get());
        $this->getJson('/api/shouts')->assertOk()->assertJsonPath('data.0.body', 'Previously public shout');
    }

    public function test_shoutbox_returns_only_new_messages_for_incremental_polling(): void
    {
        $author = $this->user();
        $first = DB::table('shouts')->insertGetId([
            'user_id' => $author->id, 'body' => 'First message', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $second = DB::table('shouts')->insertGetId([
            'user_id' => $author->id, 'body' => 'Second message', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->getJson('/api/shouts?after='.$first)->assertOk()->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.id', $second);
        $this->assertNoCredentials($response);
    }

    public function test_shoutbox_poll_batches_distinct_authors_without_changing_order_or_public_fields(): void
    {
        $expected = [];
        for ($i = 0; $i < 30; $i++) {
            $author = $this->user();
            $id = DB::table('shouts')->insertGetId([
                'user_id' => $author->id, 'body' => 'Shout '.$i,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $expected[] = ['id' => $id, 'user_id' => $author->id, 'name' => $author->name];
        }

        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $response = $this->getJson('/api/shouts')->assertOk()->assertJsonCount(30, 'data');
        $queries = DB::connection()->getQueryLog();
        DB::connection()->disableQueryLog();
        DB::connection()->flushQueryLog();

        foreach ($expected as $position => $item) {
            $response->assertJsonPath('data.'.$position.'.id', $item['id'])
                ->assertJsonPath('data.'.$position.'.author.id', $item['user_id'])
                ->assertJsonPath('data.'.$position.'.author.name', $item['name']);
        }
        // The 5-second polling path must not execute an author lookup per
        // shout; the response for 30 distinct authors uses one batched SELECT.
        $userSelects = array_filter($queries, static function (array $entry): bool {
            $sql = strtolower($entry['query']);
            return str_contains($sql, 'from "users"') || str_contains($sql, 'from `users`');
        });
        $this->assertCount(1, $userSelects, 'Shout polling reintroduced N+1 author SELECTs.');
        $this->assertNoCredentials($response);
    }

    public function test_rankings_choose_top_members_by_the_displayed_score_before_limiting(): void
    {
        $category = $this->category();
        $voter = $this->user();
        for ($i = 0; $i < 51; $i++) {
            $author = $this->user();
            [, $post] = $this->thread($author, $category);
            DB::table('reactions')->insert([
                'post_id' => $post, 'user_id' => $voter->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $best = $this->user();
        [$thread] = $this->thread($best, $category);
        for ($i = 0; $i < 49; $i++) {
            $this->makePost($best, $thread, 'A useful contribution '.$i);
        }

        $response = $this->getJson('/api/rankings')->assertOk();
        $response->assertJsonPath('data.0.id', $best->id);
        $scores = array_column($response->json('data'), 'score');
        $sorted = $scores;
        rsort($sorted, SORT_NUMERIC);
        $this->assertSame($sorted, $scores);
        $this->assertNoCredentials($response);
    }

    public function test_public_rankings_do_not_count_private_posts_or_reactions(): void
    {
        $staff = $this->user('admin');
        $voter = $this->user('moderator');
        [, $post] = $this->thread($staff, $this->category(true));
        DB::table('reactions')->insert([
            'post_id' => $post, 'user_id' => $voter->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $response = $this->getJson('/api/rankings')->assertOk();
        $member = collect($response->json('data'))->firstWhere('id', $staff->id);
        if ($member !== null) {
            $this->assertSame(0, $member['posts_count']);
            $this->assertSame(0, $member['reactions_count']);
            $this->assertSame(0, $member['score']);
        } else {
            $this->assertNotContains($staff->id, array_column($response->json('data'), 'id'));
        }
    }

    public function test_banned_session_cannot_delete_existing_shout_but_active_owner_can(): void
    {
        $banned = $this->user();
        $active = $this->user();
        $bannedShout = DB::table('shouts')->insertGetId([
            'user_id' => $banned->id, 'body' => 'Message posted before ban',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $activeShout = DB::table('shouts')->insertGetId([
            'user_id' => $active->id, 'body' => 'Owner may still delete',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Ban was applied AFTER original publication; the session persists.
        $banned->forceFill(['is_banned' => true])->save();
        $this->actingAs($banned)->deleteJson('/api/shouts/'.$bannedShout)
            ->assertForbidden()->assertJsonPath('message', __('server.account_blocked'));
        $this->assertDatabaseHas('shouts', ['id' => $bannedShout]);
        $this->actingAs($active)->deleteJson('/api/shouts/'.$bannedShout)->assertForbidden();
        $this->actingAs($active)->deleteJson('/api/shouts/'.$activeShout)->assertOk();
        $this->assertDatabaseMissing('shouts', ['id' => $activeShout]);
        $this->assertDatabaseHas('shouts', ['id' => $bannedShout]);
    }

    public function test_shout_delete_statement_rechecks_current_ban_and_staff_role(): void
    {
        $owner = $this->user();
        $moderator = $this->user('moderator');
        $other = $this->user();
        $administrator = $this->user('admin');
        $shout = DB::table('shouts')->insertGetId([
            'user_id' => $owner->id, 'body' => 'Moderated text',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Build the actual DELETE query before changing account state to
        // model an in-flight request with a stale authenticated User object.
        $staleOwner = \App\Services\ShoutboxDeleteQuery::eligible($shout, $owner->id);
        DB::table('users')->where('id', $owner->id)->update(['is_banned' => true]);
        $this->assertSame(0, $staleOwner->delete());
        $this->assertDatabaseHas('shouts', ['id' => $shout]);

        $staleModerator = \App\Services\ShoutboxDeleteQuery::eligible($shout, $moderator->id);
        DB::table('users')->where('id', $moderator->id)->update(['role' => 'member']);
        $this->assertSame(0, $staleModerator->delete());
        $this->assertDatabaseHas('shouts', ['id' => $shout]);
        $this->assertSame(0, \App\Services\ShoutboxDeleteQuery::eligible($shout, $other->id)->delete());
        $this->assertSame(1, \App\Services\ShoutboxDeleteQuery::eligible($shout, $administrator->id)->delete());
        $this->assertDatabaseMissing('shouts', ['id' => $shout]);
    }

    public function test_member_cannot_remove_another_users_shout(): void
    {
        $owner = $this->user();
        $member = $this->user();
        $id = DB::table('shouts')->insertGetId([
            'user_id' => $owner->id, 'body' => 'Original', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($member)->deleteJson('/api/shouts/'.$id)->assertForbidden();
        $this->assertDatabaseHas('shouts', ['id' => $id]);
    }

    public function test_executable_and_svg_uploads_are_rejected(): void
    {
        Storage::fake('local');
        $owner = $this->user();
        [, $post] = $this->thread($owner, $this->category());
        $this->actingAs($owner);
        foreach ([
            ['shell.php', '<?php echo "executable";'],
            ['image.svg', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
        ] as [$name, $content]) {
            $this->post('/api/posts/'.$post.'/attachments', [
                'file' => UploadedFile::fake()->createWithContent($name, $content),
            ], ['Accept' => 'application/json'])->assertUnprocessable();
        }
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_private_attachment_download_requires_category_access(): void
    {
        Storage::fake('local');
        $owner = $this->user('admin');
        $member = $this->user();
        [, $post] = $this->thread($owner, $this->category(true));
        Storage::disk('local')->put('attachments/private.txt', 'Confidential contents');
        $id = DB::table('attachments')->insertGetId([
            'post_id' => $post, 'user_id' => $owner->id, 'path' => 'attachments/private.txt',
            'name' => 'private.txt', 'mime' => 'text/plain', 'size' => 21,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertDenied($this->get('/attachments/'.$id, ['Accept' => 'application/json']));
        $this->actingAs($member);
        $this->assertDenied($this->get('/attachments/'.$id, ['Accept' => 'application/json']));
    }

    public function test_admin_cannot_ban_self_or_another_admin(): void
    {
        $admin = $this->user('admin');
        $otherAdmin = $this->user('admin');
        $this->actingAs($admin);
        foreach ([$admin, $otherAdmin] as $target) {
            $response = $this->patchJson('/api/admin/users/'.$target->id, ['is_banned' => true]);
            $this->assertContains($response->status(), [403, 422]);
        }
        $this->assertDatabaseHas('users', ['id' => $admin->id, 'is_banned' => false]);
        $this->assertDatabaseHas('users', ['id' => $otherAdmin->id, 'is_banned' => false]);
    }

    public function test_csrf_middleware_rejects_missing_token_and_accepts_matching_session_token(): void
    {
        $this->app->bind(PreventRequestForgery::class, EnforcedCsrfForForumTests::class);
        $user = $this->user();
        $this->actingAs($user);

        $this->withSession(['_token' => 'known-test-csrf-token'])
            ->postJson('/api/shouts', ['body' => 'Missing token must fail'])->assertStatus(419);
        $this->assertDatabaseCount('shouts', 0);

        $this->withSession(['_token' => 'known-test-csrf-token'])
            ->withHeader('X-CSRF-TOKEN', 'known-test-csrf-token')
            ->postJson('/api/shouts', ['body' => 'Valid session token succeeds'])->assertCreated();
        $this->assertDatabaseCount('shouts', 1);
    }

    private function setting(string $key, mixed $value): void
    {
        DB::table('settings')->updateOrInsert(['key' => $key], ['value' => json_encode($value)]);
    }

    private function user(string $role = 'member'): User
    {
        $n = ++$this->userSequence;
        $user = new User;
        $user->forceFill([
            'name' => 'Test member '.$n, 'email' => 'member'.$n.'@example.test',
            'password' => Hash::make('SafeForumPass!2026'), 'role' => $role,
            'bio' => '', 'color' => 'violet', 'is_banned' => false,
        ])->save();

        return $user;
    }

    private function category(bool $private = false, ?string $name = null): int
    {
        $n = ++$this->categorySequence;

        return DB::table('categories')->insertGetId([
            'name' => $name ?? 'Category '.$n, 'slug' => 'category-'.$n,
            'description' => 'Test category', 'color' => 'violet', 'icon' => 'code',
            'parent_id' => null, 'sort_order' => $n, 'is_private' => $private,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{int, int} */
    private function thread(User $author, int $category, string $title = 'A useful discussion', bool $locked = false, string $body = 'The original post.'): array
    {
        $id = DB::table('threads')->insertGetId([
            'category_id' => $category, 'user_id' => $author->id,
            'title' => $title, 'slug' => 'test-discussion', 'is_pinned' => false,
            'is_locked' => $locked, 'views' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$id, $this->makePost($author, $id, $body)];
    }

    private function makePost(User $author, int $thread, string $body): int
    {
        return DB::table('posts')->insertGetId([
            'thread_id' => $thread, 'user_id' => $author->id, 'body' => $body,
            'body_format' => 'markdown', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function assertDenied(TestResponse $response): void
    {
        $this->assertContains($response->status(), [403, 404], $response->getContent());
    }

    private function assertNoCredentials(TestResponse $response): void
    {
        foreach (['"password"', '"remember_token"', '"legacy_password_hash"', '"legacy_password_salt"'] as $key) {
            $this->assertStringNotContainsString($key, $response->getContent());
        }
    }
}

/** Force production CSRF behavior inside the Laravel test runner. */
class EnforcedCsrfForForumTests extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
