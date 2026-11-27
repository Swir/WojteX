<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Requires a real Laravel HTTP/PDO runtime; not executed by fake DB smokes. */
class PrivateMessageReadReceiptLiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('r', 32))]);
        DB::table('settings')->insert(['key'=>'setup_complete', 'value'=>'true']);
    }

    private function account(string $name): User
    {
        $user=new User();
        $user->forceFill([
            'name'=>$name, 'email'=>strtolower($name).'@example.test',
            'password'=>'SyntheticPasswordForTests2026!', 'role'=>'member',
            'is_banned'=>false, 'color'=>'violet',
        ])->save();
        return $user;
    }

    public function test_read_receipt_is_private_idempotent_and_revoked_after_ban_or_hide(): void
    {
        $sender=$this->account('Sender');
        $recipient=$this->account('Recipient');
        $other=$this->account('Other');
        $id=DB::table('private_messages')->insertGetId([
            'sender_id'=>$sender->id, 'recipient_id'=>$recipient->id,
            'body'=>'Synthetic private message', 'created_at'=>now(), 'updated_at'=>now(),
        ]);

        $this->actingAs($sender);
        $this->patchJson('/api/messages/'.$id.'/read')->assertNotFound();
        $this->actingAs($other);
        $this->patchJson('/api/messages/'.$id.'/read')->assertNotFound();
        $this->actingAs($recipient);
        $this->patchJson('/api/messages/'.$id.'/read')->assertOk();
        $first=DB::table('private_messages')->where('id',$id)->value('read_at');
        $this->assertNotNull($first);
        $this->patchJson('/api/messages/'.$id.'/read')->assertOk();
        $this->assertSame($first, DB::table('private_messages')->where('id',$id)->value('read_at'));

        DB::table('private_messages')->where('id',$id)->update(['read_at'=>null]);
        DB::table('users')->where('id',$recipient->id)->update(['is_banned'=>true]);
        $this->patchJson('/api/messages/'.$id.'/read')->assertForbidden();
        $this->assertNull(DB::table('private_messages')->where('id',$id)->value('read_at'));

        DB::table('users')->where('id',$recipient->id)->update(['is_banned'=>false]);
        DB::table('private_messages')->where('id',$id)->update(['recipient_hidden_at'=>now()]);
        $this->patchJson('/api/messages/'.$id.'/read')->assertNotFound();
        $this->assertNull(DB::table('private_messages')->where('id',$id)->value('read_at'));
    }

    public function test_unread_count_reports_only_visible_incoming_messages_for_the_active_account(): void
    {
        $sender = $this->account('Sender');
        $recipient = $this->account('Recipient');
        $other = $this->account('Other');
        $message = fn (User $from, User $to, array $attributes = []) => DB::table('private_messages')->insertGetId([
            ...$attributes, 'sender_id' => $from->id, 'recipient_id' => $to->id,
            'body' => 'Synthetic private message', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $visible = $message($sender, $recipient, ['sender_hidden_at' => now()]);
        $message($sender, $recipient, ['recipient_hidden_at' => now()]);
        $message($sender, $recipient, ['read_at' => now()]);
        $message($recipient, $other);
        $message($sender, $other);

        $url = '/api/messages/unread-count';
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($other)->getJson($url)->assertOk()->assertExactJson(['unread' => 2]);
        $this->actingAs($recipient)->getJson($url.'?user_id='.$other->id)->assertOk()->assertExactJson(['unread' => 1]);
        $this->patchJson('/api/messages/'.$visible.'/read')->assertOk();
        $this->getJson($url)->assertOk()->assertExactJson(['unread' => 0]);
        DB::table('users')->where('id', $recipient->id)->update(['is_banned' => true]);
        $this->getJson($url)->assertForbidden();
    }

    public function test_mark_all_reads_only_visible_incoming_messages_through_the_loaded_cutoff(): void
    {
        $sender = $this->account('Sender');
        $recipient = $this->account('Recipient');
        $other = $this->account('Other');
        $send = fn (User $from, User $to, array $attributes = []) => DB::table('private_messages')->insertGetId([
            ...$attributes,
            'sender_id' => $from->id, 'recipient_id' => $to->id, 'body' => 'Synthetic message',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $read = $send($sender, $recipient, ['read_at' => '2026-01-02 03:04:05']);
        $outgoing = $send($recipient, $sender);
        $unrelated = $send($sender, $other);
        $hidden = $send($sender, $recipient, ['recipient_hidden_at' => now()]);
        foreach (range(1, 31) as $i) {
            $send($sender, $recipient);
        }
        $hiddenAfterLoad = $send($sender, $recipient);

        $this->putJson('/api/messages/read-all', ['through_id' => $hiddenAfterLoad])->assertUnauthorized();
        $this->actingAs($recipient);
        $snapshot = $this->getJson('/api/messages?folder=unread&page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('unread', 32)->assertJsonPath('unread_through_id', $hiddenAfterLoad);
        $data = ['through_id' => $snapshot->json('unread_through_id')];
        $this->putJson('/api/messages/read-all', ['through_id' => 0])->assertUnprocessable()->assertJsonValidationErrors('through_id');

        $this->deleteJson('/api/messages/'.$hiddenAfterLoad)->assertOk();
        $new = $send($sender, $recipient);
        $this->putJson('/api/messages/read-all', $data)->assertOk()->assertExactJson(['marked' => 31]);
        $this->putJson('/api/messages/read-all', $data)->assertOk()->assertExactJson(['marked' => 0]);
        $this->assertDatabaseHas('private_messages', ['id' => $read, 'read_at' => '2026-01-02 03:04:05']);
        foreach ([$outgoing, $unrelated, $hidden, $hiddenAfterLoad, $new] as $unread) {
            $this->assertDatabaseHas('private_messages', ['id' => $unread, 'read_at' => null]);
        }
        $this->getJson('/api/messages?folder=unread')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $new)->assertJsonPath('unread', 1)->assertJsonPath('unread_through_id', $new);

        DB::table('users')->where('id', $recipient->id)->update(['is_banned' => true]);
        $this->putJson('/api/messages/read-all', ['through_id' => $new])->assertForbidden();
        $this->assertDatabaseHas('private_messages', ['id' => $new, 'read_at' => null]);
    }
}
