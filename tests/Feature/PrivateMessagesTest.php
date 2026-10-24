<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** HTTP + SQLite tests; run with a complete PHP 8.3+ runtime. */
class PrivateMessagesTest extends TestCase
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
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function account(string $name, string $email, string $role = 'member', bool $banned = false): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name, 'email' => $email, 'password' => 'SomeLongPassword2026!',
            'role' => $role, 'is_banned' => $banned, 'color' => 'violet',
        ])->save();
        return $user;
    }

    public function test_only_participants_can_access_and_only_recipient_can_mark_read(): void
    {
        $sender = $this->account('Alice', 'alice@example.test');
        $receiver = $this->account('Bob', 'bob@example.test');
        $other = $this->account('Charlie', 'charlie@example.test', 'admin');

        $this->getJson('/api/messages')->assertUnauthorized();
        $this->postJson('/api/messages', ['recipient' => 'Bob', 'body' => 'Secret'])->assertUnauthorized();
        $this->actingAs($sender);
        $id = $this->postJson('/api/messages', ['recipient' => 'Bob', 'body' => '<script>alert(1)</script>'])
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('private_messages', [
            'id' => $id, 'sender_id' => $sender->id, 'recipient_id' => $receiver->id,
            'read_at' => null,
        ]);
        $this->getJson('/api/messages')->assertOk()->assertJsonPath('data.0.outgoing', true)
            ->assertJsonPath('data.0.body', '<script>alert(1)</script>')
            ->assertJsonPath('unread', 0);
        $this->patchJson('/api/messages/'.$id.'/read')->assertNotFound();
        $this->actingAs($other); // Admin cannot inspect private user messages.
        $this->getJson('/api/messages')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/messages/'.$id.'/read')->assertNotFound();
        $this->actingAs($receiver);
        $this->getJson('/api/messages')->assertOk()->assertJsonPath('data.0.outgoing', false)
            ->assertJsonPath('unread', 1);
        $this->patchJson('/api/messages/'.$id.'/read')->assertOk();
        $this->patchJson('/api/messages/'.$id.'/read')->assertOk(); // Idempotent.
        $this->getJson('/api/messages')->assertOk()->assertJsonPath('unread', 0);
        $this->assertDatabaseMissing('private_messages', ['id' => $id, 'read_at' => null]);
        $this->patchJson('/api/messages/999999/read')->assertNotFound();
    }

    public function test_rejects_self_banned_unknown_ambiguous_and_empty_messages(): void
    {
        $alice = $this->account('Alice', 'alice2@example.test');
        $this->account('Duplicate', 'dup1@example.test');
        $this->account('Duplicate', 'dup2@example.test');
        $this->account('Banned', 'banned@example.test', 'member', true);
        $this->account('Bob', 'bob2@example.test');
        $this->actingAs($alice);
        foreach (['Alice', 'Duplicate', 'Banned', 'Unknown'] as $recipient) {
            $this->postJson('/api/messages', ['recipient' => $recipient, 'body' => 'Should not send'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/messages', ['recipient' => 'Bob', 'body' => '    '])
            ->assertUnprocessable();
        $this->postJson('/api/messages', ['recipient' => 'Bob', 'body' => str_repeat('X', 4001)])
            ->assertUnprocessable();
        $this->assertDatabaseCount('private_messages', 0);
        $this->actingAs(User::where('name', 'Banned')->first());
        $this->getJson('/api/messages')->assertForbidden();
        $this->assertGuest(); // The first denied request revokes the banned session.
        $this->postJson('/api/messages', ['recipient' => 'Alice', 'body' => 'Hello'])->assertUnauthorized();
    }

    public function test_mailbox_is_paginated_and_shows_only_own_messages(): void
    {
        $a = $this->account('Account A', 'a@example.test');
        $b = $this->account('Account B', 'b@example.test');
        $c = $this->account('Account C', 'c@example.test');
        $now = now();
        foreach (range(1, 35) as $i) {
            DB::table('private_messages')->insert([
                'sender_id' => $a->id, 'recipient_id' => $b->id,
                'body' => 'Message '.$i, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        DB::table('private_messages')->insert([
            'sender_id' => $c->id, 'recipient_id' => $b->id,
            'body' => 'Other conversation', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->actingAs($a);
        $this->getJson('/api/messages')->assertOk()->assertJsonCount(30, 'data')
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('data.0.body', 'Message 35');
        $this->getJson('/api/messages?page=2')->assertOk()->assertJsonCount(5, 'data');
        $this->actingAs($c);
        $this->getJson('/api/messages')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Other conversation');
    }

    public function test_recipient_search_disambiguates_names_without_exposing_account_details(): void
    {
        $alice = $this->account('Alice', 'alice-search@example.test');
        $duplicateA = $this->account('Duplicate', 'dupa-search@example.test');
        $duplicateB = $this->account('Duplicate', 'dupb-search@example.test');
        $this->account('Dup Banned', 'banned-search@example.test', 'member', true);
        $this->account('Percent%Name', 'percent-search@example.test');
        $this->account('Underscore_Name', 'underscore-search@example.test');
        $this->account('NormalName', 'normal-search@example.test');

        $this->getJson('/api/messages/recipients?q=Dup')->assertUnauthorized();
        $this->actingAs($alice);
        $matches = $this->getJson('/api/messages/recipients?q=Dup')
            ->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame([$duplicateA->id, $duplicateB->id], array_column($matches, 'id'));
        $this->assertSame(['id', 'name'], array_keys($matches[0]));
        $this->assertSame('Duplicate', $matches[0]['name']);
        $this->getJson('/api/messages/recipients?q=Ali')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/messages/recipients?q=%25N')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Percent%Name');
        $this->getJson('/api/messages/recipients?q=_')
            ->assertUnprocessable(); // Search minimum is two characters.
        $this->getJson('/api/messages/recipients?q=_N')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Underscore_Name');
        $this->getJson('/api/messages/recipients?q=%20%20')->assertUnprocessable();
        $this->getJson('/api/messages/recipients?q=%20A%20')->assertUnprocessable();

        // Numeric account IDs, unlike display names, remain unambiguous.
        $id = $this->postJson('/api/messages', ['recipient_id' => $duplicateB->id, 'body' => 'Hello by ID'])
            ->assertCreated()->json('id');
        $this->assertDatabaseHas('private_messages', [
            'id' => $id, 'sender_id' => $alice->id, 'recipient_id' => $duplicateB->id,
        ]);
        foreach ([$alice->id, 999999999] as $badId) {
            $this->postJson('/api/messages', ['recipient_id' => $badId, 'body' => 'Not delivered'])
                ->assertUnprocessable();
        }
        $this->assertDatabaseCount('private_messages', 1);
    }

    public function test_hide_message_only_affects_viewers_copy_and_not_the_other_participant(): void
    {
        $sender = $this->account('Sender', 'sender-hide@example.test');
        $recipient = $this->account('Receiver', 'receiver-hide@example.test');
        $stranger = $this->account('Stranger', 'stranger-hide@example.test', 'admin');

        $this->deleteJson('/api/messages/123')->assertUnauthorized();
        $this->actingAs($sender);
        $id = $this->postJson('/api/messages', ['recipient_id' => $recipient->id, 'body' => 'Private retained copy'])
            ->assertCreated()->json('id');
        $this->actingAs($stranger);
        $this->deleteJson('/api/messages/'.$id)->assertNotFound();
        $this->actingAs($sender);
        $this->deleteJson('/api/messages/'.$id)->assertOk();
        $this->deleteJson('/api/messages/'.$id)->assertNotFound();
        $this->getJson('/api/messages')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('private_messages', ['id' => $id, 'body' => 'Private retained copy']);

        $this->actingAs($recipient);
        $this->getJson('/api/messages')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('unread', 1);
        $this->patchJson('/api/messages/'.$id.'/read')->assertOk();
        $this->deleteJson('/api/messages/'.$id)->assertOk();
        $this->getJson('/api/messages')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread', 0);
        $this->patchJson('/api/messages/'.$id.'/read')->assertNotFound();
        $this->assertDatabaseHas('private_messages', ['id' => $id, 'body' => 'Private retained copy']);
    }

    public function test_user_block_is_owner_only_symmetric_for_future_messages_and_keeps_history(): void
    {
        $alice = $this->account('Block Alice', 'alice-block@example.test');
        $bob = $this->account('Block Bob', 'bob-block@example.test');
        $carol = $this->account('Block Carol', 'carol-block@example.test', 'admin');

        $this->getJson('/api/messages/blocks')->assertUnauthorized();
        $this->postJson('/api/messages/blocks', ['user_id' => $alice->id])->assertUnauthorized();
        $this->actingAs($alice);
        $existingId = $this->postJson('/api/messages', [
            'recipient_id' => $bob->id, 'body' => 'Already delivered',
        ])->assertCreated()->json('id');

        $this->actingAs($bob);
        $this->postJson('/api/messages/blocks', ['user_id' => $bob->id])->assertUnprocessable();
        $this->postJson('/api/messages/blocks', ['user_id' => 999999])->assertUnprocessable();
        $this->postJson('/api/messages/blocks', ['user_id' => $alice->id])->assertOk();
        $this->postJson('/api/messages/blocks', ['user_id' => $alice->id])->assertOk();
        $this->assertDatabaseCount('private_message_blocks', 1);
        $this->getJson('/api/messages/blocks')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $alice->id);
        $this->postJson('/api/messages', ['recipient_id' => $alice->id, 'body' => 'Blocked outgoing'])
            ->assertUnprocessable();
        $this->postJson('/api/messages', ['recipient' => 'Block Alice', 'body' => 'Blocked legacy'])
            ->assertUnprocessable();
        $this->getJson('/api/messages/recipients?q=Block')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $carol->id);
        $this->getJson('/api/messages')->assertOk()
            ->assertJsonPath('data.0.body', 'Already delivered');
        $this->deleteJson('/api/messages/blocks/'.$carol->id)->assertOk(); // Other person's block remains untouched.
        $this->assertDatabaseCount('private_message_blocks', 1);

        $this->actingAs($alice);
        $this->getJson('/api/messages/blocks')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/messages', ['recipient_id' => $bob->id, 'body' => 'Blocked incoming'])
            ->assertUnprocessable();
        $this->getJson('/api/messages/recipients?q=Block')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $carol->id);
        $this->getJson('/api/messages')->assertOk()->assertJsonPath('data.0.id', $existingId);
        // A third party cannot inspect or modify Bob's list.
        $this->actingAs($carol);
        $this->getJson('/api/messages/blocks')->assertOk()->assertJsonCount(0, 'data');
        $this->deleteJson('/api/messages/blocks/'.$alice->id)->assertOk();
        $this->assertDatabaseCount('private_message_blocks', 1);
        $this->postJson('/api/messages', ['recipient_id' => $alice->id, 'body' => 'Allowed elsewhere'])
            ->assertCreated();

        $this->actingAs($bob);
        $this->deleteJson('/api/messages/blocks/'.$alice->id)->assertOk();
        $this->deleteJson('/api/messages/blocks/'.$alice->id)->assertOk(); // Idempotent.
        $this->assertDatabaseCount('private_message_blocks', 0);
        // These block/send operations share the per-member minute budget.
        $this->postJson('/api/messages', ['recipient_id' => $alice->id, 'body' => 'Allowed again'])
            ->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->postJson('/api/messages', ['recipient_id' => $alice->id, 'body' => 'Allowed again'])
            ->assertCreated();
    }

    public function test_banned_accounts_cannot_change_contact_blocks(): void
    {
        $banned = $this->account('Blocked banned user', 'banned-blocks@example.test', 'member', true);
        $target = $this->account('Ordinary member', 'ordinary-blocks@example.test');
        $this->actingAs($banned);
        $this->getJson('/api/messages/blocks')->assertForbidden();
        $this->assertGuest();
        $this->postJson('/api/messages/blocks', ['user_id' => $target->id])->assertUnauthorized();
        $this->deleteJson('/api/messages/blocks/'.$target->id)->assertUnauthorized();
    }

    public function test_banned_accounts_cannot_search_recipients(): void
    {
        $banned = $this->account('Bad', 'bad-search@example.test', 'member', true);
        $this->actingAs($banned);
        $this->getJson('/api/messages/recipients?q=Al')->assertForbidden();
    }
}
