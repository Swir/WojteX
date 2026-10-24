<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ForumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Needs full PHP PDO SQLite/HTTP runtime; source authored but NOT run on this host. */
class PrivateMessagePreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
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
            'password' => 'SomeLongPassword2026!', 'role' => 'member',
            'color' => 'violet', 'is_banned' => false,
        ])->save();
        return $user;
    }

    public function test_member_may_opt_out_of_incoming_messages_without_erasing_history(): void
    {
        $alice = $this->account('Preference Alice');
        $bob = $this->account('Preference Bob');

        $this->actingAs($alice);
        $oldId = $this->postJson('/api/messages', [
            'recipient_id' => $bob->id, 'body' => 'Before changing preferences',
        ])->assertCreated()->json('id');

        $this->actingAs($bob);
        $this->getJson('/api/bootstrap')->assertOk()
            ->assertJsonPath('user.allow_private_messages', true);
        $this->patchJson('/api/profile', [
            'name' => $bob->name, 'bio' => '', 'allow_private_messages' => false,
        ])->assertOk()->assertJsonPath('user.allow_private_messages', false);
        $this->getJson('/api/messages')->assertOk()
            ->assertJsonPath('data.0.id', $oldId);
        $this->assertFalse(array_key_exists('allow_private_messages', ForumService::publicUser($bob->fresh())));

        $this->actingAs($alice);
        $this->getJson('/api/messages/recipients?q=Preference')->assertOk()
            ->assertJsonCount(0, 'data');
        $this->postJson('/api/messages', [
            'recipient_id' => $bob->id, 'body' => 'Blocked by personal opt-out',
        ])->assertUnprocessable();
        $this->postJson('/api/messages', [
            'recipient' => $bob->name, 'body' => 'Legacy name blocked too',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('private_messages', 1);
        $this->getJson('/api/messages')->assertOk()->assertJsonPath('data.0.id', $oldId);

        // Opt-out controls receiving only; Bob can still contact consenting Alice.
        $this->actingAs($bob);
        $this->postJson('/api/messages', [
            'recipient_id' => $alice->id, 'body' => 'Outgoing still allowed',
        ])->assertCreated();
        $this->assertDatabaseCount('private_messages', 2);

        // Old clients that do not know the flag must preserve the opt-out.
        $this->patchJson('/api/profile', ['name' => $bob->name, 'bio' => 'Updated'])
            ->assertOk()->assertJsonPath('user.allow_private_messages', false);
        $this->patchJson('/api/profile', [
            'name' => $bob->name, 'bio' => '', 'allow_private_messages' => 'bad',
        ])->assertUnprocessable();
        $this->patchJson('/api/profile', [
            'name' => $bob->name, 'bio' => '', 'allow_private_messages' => true,
        ])->assertOk()->assertJsonPath('user.allow_private_messages', true);
        $this->actingAs($alice);
        $this->getJson('/api/messages/recipients?q=Preference')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bob->id);
        $this->postJson('/api/messages', [
            'recipient_id' => $bob->id, 'body' => 'Re-enabled',
        ])->assertCreated();
    }
}
