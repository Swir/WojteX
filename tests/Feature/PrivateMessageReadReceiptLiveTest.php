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
}
