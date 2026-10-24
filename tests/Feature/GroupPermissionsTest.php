<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Integration cases requiring PHP with PDO SQLite; smoke policy is executable without it. */
class GroupPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function account(string $role = 'member', string $email = 'member@example.test'): User
    {
        $user = new User();
        $user->forceFill(['name' => $role.' '.$email, 'email' => $email,
            'password' => 'SecureAccountPassword2026!', 'role' => $role, 'is_banned' => false,
            'color' => 'violet'])->save();
        return $user;
    }

    private function category(bool $private = true, ?int $parentId = null): int
    {
        static $index = 0;
        $index++;
        return DB::table('categories')->insertGetId([
            'name' => 'Room '.$index, 'slug' => 'room-'.$index, 'description' => '',
            'color' => 'violet', 'icon' => 'shield', 'parent_id' => $parentId,
            'sort_order' => 0, 'is_private' => $private,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function privateTopic(User $admin, int $category): int
    {
        $thread = DB::table('threads')->insertGetId([
            'category_id' => $category, 'user_id' => $admin->id,
            'title' => 'Confidential project roadmap', 'slug' => 'confidential-roadmap',
            'views' => 0, 'is_pinned' => false, 'is_locked' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('posts')->insert(['thread_id' => $thread, 'user_id' => $admin->id,
            'body' => 'Confidential details.', 'body_format' => 'markdown',
            'created_at' => now(), 'updated_at' => now()]);
        return $thread;
    }

    private function group(User $admin): int
    {
        $this->actingAs($admin);
        return $this->postJson('/api/admin/groups', ['name' => 'Trusted builders'])
            ->assertCreated()->json('id');
    }

    private function permission(int $groupId, int $categoryId, bool $view, bool $create = false, bool $reply = false): void
    {
        $this->putJson('/api/admin/groups/'.$groupId.'/categories/'.$categoryId,
            ['view' => $view, 'create' => $create, 'reply' => $reply])->assertOk();
    }

    public function test_members_need_explicit_private_view_and_separate_write_capabilities(): void
    {
        $admin = $this->account('admin', 'admin@example.test');
        $member = $this->account();
        $category = $this->category();
        $thread = $this->privateTopic($admin, $category);
        $groupId = $this->group($admin);
        $this->putJson('/api/admin/groups/'.$groupId.'/users/'.$member->id, ['enabled' => true])->assertOk();
        $this->actingAs($member);
        $this->getJson('/api/threads/'.$thread)->assertNotFound();
        $this->getJson('/api/threads?q=Confidential')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($admin);
        $this->permission($groupId, $category, true);
        $this->actingAs($member);
        $this->getJson('/api/threads/'.$thread)->assertOk()->assertJsonPath('can_reply', false);
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('categories.0.can_create', false);
        $this->postJson('/api/threads/'.$thread.'/posts', ['body' => 'Read-only reply'])->assertForbidden();
        $this->postJson('/api/threads', ['category_id' => $category, 'title' => 'Not allowed topic', 'body' => 'Blocked'])->assertForbidden();
        $this->actingAs($admin);
        $this->permission($groupId, $category, true, false, true);
        $this->actingAs($member);
        $replyId = $this->postJson('/api/threads/'.$thread.'/posts', ['body' => 'Allowed reply'])
            ->assertCreated()->json('post.id');
        $this->postJson('/api/threads', ['category_id' => $category, 'title' => 'Not allowed topic', 'body' => 'Blocked'])->assertForbidden();
        $this->actingAs($admin);
        $this->permission($groupId, $category, true); // Revoke write rights without hiding previous posts.
        $this->actingAs($member);
        $this->patchJson('/api/posts/'.$replyId, ['body' => 'Edited despite revoked rights'])->assertForbidden();
        $this->deleteJson('/api/posts/'.$replyId)->assertForbidden();
        $this->postJson('/api/posts/'.DB::table('posts')->where('thread_id', $thread)->min('id').'/reaction')->assertForbidden();
        $this->postJson('/api/posts/'.$replyId.'/attachments')->assertForbidden();
        $this->actingAs($admin);
        $this->permission($groupId, $category, true, true, true);
        $this->actingAs($member);
        $this->postJson('/api/threads', ['category_id' => $category, 'title' => 'Allowed member topic', 'body' => 'Welcome'])->assertCreated();
        $this->actingAs($admin);
        $this->putJson('/api/admin/groups/'.$groupId.'/users/'.$member->id, ['enabled' => false])->assertOk();
        $this->actingAs($member);
        $this->getJson('/api/threads/'.$thread)->assertNotFound();
    }

    public function test_private_parent_grant_is_required_even_for_public_child(): void
    {
        $admin = $this->account('admin', 'admin2@example.test');
        $member = $this->account('member', 'reader@example.test');
        $parent = $this->category(true);
        $child = $this->category(false, $parent);
        $thread = $this->privateTopic($admin, $child);
        $groupId = $this->group($admin);
        $this->putJson('/api/admin/groups/'.$groupId.'/users/'.$member->id, ['enabled' => true])->assertOk();
        $this->actingAs($member);
        $this->getJson('/api/threads/'.$thread)->assertNotFound();
        $this->actingAs($admin);
        $this->permission($groupId, $parent, true);
        $this->actingAs($member);
        $this->getJson('/api/threads/'.$thread)->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('categories.1.can_create', true);
        $this->getJson('/api/threads?q=Confidential')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($admin);
        $this->deleteJson('/api/admin/groups/'.$groupId)->assertOk();
        $this->actingAs($member);
        $this->getJson('/api/threads/'.$thread)->assertNotFound();
        $this->getJson('/api/threads?q=Confidential')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_members_cannot_manage_groups_or_escalate_permissions(): void
    {
        $admin = $this->account('admin', 'admin3@example.test');
        $member = $this->account();
        $category = $this->category(true);
        $groupId = $this->group($admin);
        $this->actingAs($member);
        $this->getJson('/api/admin/groups')->assertForbidden();
        $this->postJson('/api/admin/groups', ['name' => 'Hijack'])->assertForbidden();
        $this->putJson('/api/admin/groups/'.$groupId.'/categories/'.$category,
            ['view' => true, 'create' => true, 'reply' => true])->assertForbidden();
        $this->putJson('/api/admin/groups/'.$groupId.'/users/'.$member->id, ['enabled' => true])->assertForbidden();
        $this->actingAs($admin);
        $this->putJson('/api/admin/groups/'.$groupId.'/categories/'.$category,
            ['view' => false, 'create' => true, 'reply' => false])->assertUnprocessable();
        $this->assertDatabaseMissing('category_group_permissions', ['group_id' => $groupId, 'category_id' => $category]);
        $this->getJson('/api/admin/groups')->assertOk()->assertJsonPath('groups.0.id', $groupId);
    }

    /** Needs PDO + Laravel HTTP to qualify; group race smoke exercises production controller separately. */
    public function test_stale_admin_object_cannot_mutate_private_group_grants(): void
    {
        $admin = $this->account('admin', 'grants-admin@example.test');
        $category = $this->category(true);
        $groupId = $this->group($admin);

        // The already-authenticated PHP User object still says admin; the DB
        // state changed after middleware could have evaluated it.
        DB::table('users')->where('id', $admin->id)->update(['role' => 'member']);
        $this->actingAs($admin);
        $this->putJson('/api/admin/groups/'.$groupId.'/categories/'.$category,
            ['view' => true, 'create' => true, 'reply' => true])->assertForbidden();
        $this->assertDatabaseMissing('category_group_permissions', [
            'group_id' => $groupId, 'category_id' => $category,
        ]);
        $this->patchJson('/api/admin/groups/'.$groupId, ['name' => 'Hijacked group'])->assertForbidden();
        $this->deleteJson('/api/admin/groups/'.$groupId)->assertForbidden();
        $this->assertDatabaseHas('forum_groups', ['id' => $groupId, 'name' => 'Trusted builders']);

        // A separate security state change (ban) must likewise invalidate
        // the cached session administrator for this endpoint.
        DB::table('users')->where('id', $admin->id)->update(['role' => 'admin', 'is_banned' => true]);
        $this->putJson('/api/admin/groups/'.$groupId.'/categories/'.$category,
            ['view' => true, 'create' => false, 'reply' => false])->assertForbidden();
        $this->assertDatabaseMissing('category_group_permissions', [
            'group_id' => $groupId, 'category_id' => $category,
        ]);
    }
}
