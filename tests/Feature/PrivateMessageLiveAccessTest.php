<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Full HTTP/PHP PDO qualification still requires dom, mbstring, xmlwriter and pdo_sqlite. */
class PrivateMessageLiveAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('p', 32))]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function user(string $name): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name,
            'email' => strtolower($name).'@example.test',
            'password' => 'OnlyForSyntheticTests2026!',
            'role' => 'member', 'is_banned' => false, 'color' => 'violet',
        ])->save();
        return $user;
    }

    public function test_stale_session_ban_blocks_every_private_message_read_and_read_receipt(): void
    {
        $sender = $this->user('Sender');
        $receiver = $this->user('Receiver');
        $id = DB::table('private_messages')->insertGetId([
            'sender_id' => $sender->id,
            'recipient_id' => $receiver->id,
            'body' => 'Synthetic confidential example',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($receiver);
        $this->getJson('/api/messages')->assertOk()
            ->assertJsonPath('data.0.body', 'Synthetic confidential example');

        // The session retains its original User; only the live DB row changes.
        DB::table('users')->where('id', $receiver->id)->update(['is_banned' => true]);
        $this->assertFalse((bool)$receiver->is_banned);
        $this->getJson('/api/messages')->assertForbidden()
            ->assertDontSee('Synthetic confidential example');
        $this->getJson('/api/messages/blocks')->assertForbidden();
        $this->getJson('/api/messages/recipients?q=Sen')->assertForbidden();
        $this->patchJson('/api/messages/'.$id.'/read')->assertForbidden();
        $this->assertDatabaseHas('private_messages', ['id'=>$id, 'read_at'=>null]);

        DB::table('users')->where('id', $receiver->id)->update(['is_banned' => false]);
        $this->getJson('/api/messages')->assertOk();
        $this->patchJson('/api/messages/'.$id.'/read')->assertOk();
        $this->assertDatabaseMissing('private_messages', ['id'=>$id, 'read_at'=>null]);
    }
}
