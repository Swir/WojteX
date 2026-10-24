<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Requires complete PHP extensions + PDO/HTTP. Not runnable in the DEV.38 cloud host. */
class PrivateMessageReportLiveAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function account(string $name): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
            'password' => 'SomeLongPassword2026!',
            'role' => 'member', 'is_banned' => false, 'color' => 'violet',
        ])->save();
        return $user;
    }

    public function test_ban_after_session_load_blocks_new_and_duplicate_report(): void
    {
        $sender = $this->account('Report Live Sender');
        $recipient = $this->account('Report Live Recipient');
        $this->actingAs($sender);
        $messageId = $this->postJson('/api/messages', [
            'recipient_id' => $recipient->id, 'body' => 'One reported message',
        ])->assertCreated()->json('id');

        $this->actingAs($recipient); // Keep the original authentication instance.
        DB::table('users')->where('id', $recipient->id)->update(['is_banned' => true]);
        $this->postJson('/api/messages/'.$messageId.'/report', ['reason' => 'spam'])
            ->assertForbidden();
        $this->assertDatabaseCount('private_message_reports', 0);

        DB::table('users')->where('id', $recipient->id)->update(['is_banned' => false]);
        $this->postJson('/api/messages/'.$messageId.'/report', ['reason' => 'spam'])
            ->assertOk();
        $this->assertDatabaseCount('private_message_reports', 1);
        DB::table('users')->where('id', $recipient->id)->update(['is_banned' => true]);
        $this->postJson('/api/messages/'.$messageId.'/report', ['reason' => 'spam'])
            ->assertForbidden();
        $this->assertDatabaseCount('private_message_reports', 1);
    }

    public function test_hidden_copy_cannot_reuse_prior_report_as_authorization(): void
    {
        $sender = $this->account('Hidden Report Sender');
        $recipient = $this->account('Hidden Report Recipient');
        $this->actingAs($sender);
        $messageId = $this->postJson('/api/messages', [
            'recipient_id' => $recipient->id, 'body' => 'Only recipient can report',
        ])->assertCreated()->json('id');
        $this->actingAs($recipient);
        $this->postJson('/api/messages/'.$messageId.'/report', ['reason' => 'spam'])
            ->assertOk();
        $this->deleteJson('/api/messages/'.$messageId)->assertOk();
        $this->postJson('/api/messages/'.$messageId.'/report', ['reason' => 'spam'])
            ->assertNotFound();
        $this->assertDatabaseCount('private_message_reports', 1);
    }
}
