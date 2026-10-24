<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Requires full PHP extensions, Laravel HTTP and PDO; NOT standalone smoke. */
class ForumReadLiveAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('f', 32))]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
        Storage::fake('local');
    }

    private function fixture(): array
    {
        $staff = new User();
        $staff->forceFill([
            'name' => 'Old Staff', 'email' => 'oldstaff@example.test',
            'password' => 'NotARealProductionPassword2026!',
            'role' => 'moderator', 'is_banned' => false, 'color' => 'violet',
        ])->save();
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'Secret department', 'slug' => 'secret-department',
            'description' => '', 'color' => 'violet', 'icon' => 'shield',
            'sort_order' => 0, 'is_private' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $threadId = DB::table('threads')->insertGetId([
            'category_id' => $categoryId, 'user_id' => $staff->id,
            'title' => 'Classified plans', 'slug' => 'classified-plans',
            'views' => 0, 'is_pinned' => false, 'is_locked' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $postId = DB::table('posts')->insertGetId([
            'thread_id' => $threadId, 'user_id' => $staff->id,
            'body' => 'Confidential department data.', 'body_format' => 'markdown',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Storage::disk('local')->put('attachments/classified.txt', 'do not disclose');
        $attachmentId = DB::table('attachments')->insertGetId([
            'post_id' => $postId, 'user_id' => $staff->id,
            'path' => 'attachments/classified.txt', 'name' => 'classified.txt',
            'mime' => 'text/plain', 'size' => 15,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return [$staff, $threadId, $attachmentId];
    }

    public function test_demoted_stale_moderator_cannot_read_private_list_detail_bootstrap_or_attachment(): void
    {
        [$staff, $threadId, $attachmentId] = $this->fixture();
        $this->actingAs($staff);
        $this->getJson('/api/threads/'.$threadId)->assertOk();

        // Deliberately retain an old authenticated Eloquent object saying
        // "moderator" while the authoritative database now says "member".
        DB::table('users')->where('id', $staff->id)->update(['role' => 'member']);
        $this->assertSame('moderator', $staff->role);
        $this->getJson('/api/threads/'.$threadId)->assertNotFound();
        $this->getJson('/api/threads?q=Classified')->assertOk()
            ->assertJsonCount(0, 'data')->assertDontSee('Classified plans');
        $this->getJson('/api/bootstrap')->assertOk()
            ->assertJsonCount(0, 'categories')->assertDontSee('Secret department');
        $this->get('/attachments/'.$attachmentId)->assertNotFound();
    }

    public function test_live_ban_or_deletion_blocks_reads_despite_cached_unbanned_session(): void
    {
        [$staff, $threadId] = $this->fixture();
        $this->actingAs($staff);
        DB::table('users')->where('id', $staff->id)->update(['is_banned' => true]);
        $this->assertFalse((bool) $staff->is_banned);
        $this->getJson('/api/threads/'.$threadId)->assertForbidden();
        $this->getJson('/api/bootstrap')->assertForbidden();

        [$removed, $otherThread] = $this->fixtureWithDistinctStaff();
        $this->actingAs($removed);
        DB::table('users')->where('id', $removed->id)->delete();
        $this->getJson('/api/threads/'.$otherThread)->assertForbidden();
    }

    private function fixtureWithDistinctStaff(): array
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Deleted Staff', 'email' => 'deleted@example.test',
            'password' => 'NotARealProductionPassword2026!',
            'role' => 'moderator', 'is_banned' => false, 'color' => 'violet',
        ])->save();
        return [$user, (int) DB::table('threads')->value('id')];
    }

    public function test_guest_stays_on_public_reads_without_private_visibility(): void
    {
        [, $threadId] = $this->fixture();
        $this->getJson('/api/threads/'.$threadId)->assertNotFound();
        $this->getJson('/api/bootstrap')->assertOk()
            ->assertJsonCount(0, 'categories');
    }
}
