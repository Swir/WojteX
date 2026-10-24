<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PostDeleteQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Requires full PHP PDO SQLite/HTTP runtime; not validated by host smoke tests. */
class PostDeleteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key'=>'base64:'.base64_encode(str_repeat('d',32)),
            'hashing.driver'=>'argon2id',
            'hashing.argon.memory'=>1024,
            'hashing.argon.time'=>1,
            'hashing.argon.threads'=>1,
        ]);
        DB::table('settings')->insert(['key'=>'setup_complete','value'=>'true']);
        Storage::fake('local');
    }

    private function account(string $name,string $role='member'): User
    {
        $account=new User();
        $account->forceFill([
            'name'=>$name,'email'=>strtolower($name).'@example.test',
            'password'=>'LongPasswordTest#2026','role'=>$role,
            'is_banned'=>false,'color'=>'violet',
        ])->save();
        return $account;
    }

    private function topic(User $owner): array
    {
        $category=DB::table('categories')->insertGetId([
            'name'=>'Delete policy room','slug'=>'delete-policy-room',
            'description'=>'','color'=>'violet','icon'=>'chat','parent_id'=>null,
            'sort_order'=>0,'is_private'=>false,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $thread=DB::table('threads')->insertGetId([
            'category_id'=>$category,'user_id'=>$owner->id,'title'=>'Delete policy',
            'slug'=>'delete-policy','views'=>0,'is_pinned'=>false,'is_locked'=>false,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $first=DB::table('posts')->insertGetId([
            'thread_id'=>$thread,'user_id'=>$owner->id,'body'=>'Keep original',
            'body_format'=>'markdown','created_at'=>now(),'updated_at'=>now(),
        ]);
        $reply=DB::table('posts')->insertGetId([
            'thread_id'=>$thread,'user_id'=>$owner->id,'body'=>'Delete reply',
            'body_format'=>'markdown','created_at'=>now(),'updated_at'=>now(),
        ]);
        return [$thread,$first,$reply];
    }

    public function test_http_first_post_and_attachment_survive_denied_deletion(): void
    {
        $owner=$this->account('Owner');
        [$thread,$first,$reply]=$this->topic($owner);
        $path='attachments/not-to-delete-on-denial.txt';
        Storage::disk('local')->put($path,'private test fixture');
        DB::table('attachments')->insert([
            'post_id'=>$reply,'user_id'=>$owner->id,'path'=>$path,
            'name'=>'fixture.txt','mime'=>'text/plain','size'=>20,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $this->actingAs($owner);
        $this->deleteJson('/api/posts/'.$first)->assertStatus(422);
        $this->assertDatabaseHas('posts',['id'=>$first,'thread_id'=>$thread]);
        Storage::disk('local')->assertExists($path);
        DB::table('threads')->where('id',$thread)->update(['is_locked'=>true]);
        $this->deleteJson('/api/posts/'.$reply)->assertForbidden();
        $this->assertDatabaseHas('posts',['id'=>$reply]);
        Storage::disk('local')->assertExists($path);
        DB::table('threads')->where('id',$thread)->update(['is_locked'=>false]);
        $this->deleteJson('/api/posts/'.$reply)->assertOk();
        $this->assertDatabaseMissing('posts',['id'=>$reply]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_sql_guard_rechecks_live_bans_and_staff_roles(): void
    {
        $owner=$this->account('Author');
        $moderator=$this->account('Moderator','moderator');
        $admin=$this->account('Administrator','admin');
        [$thread,$first,$reply]=$this->topic($owner);
        $delete=fn(int $actor, int $post) => PostDeleteQuery::eligible($post,$actor,$thread,$first)->delete();
        DB::table('users')->where('id',$owner->id)->update(['is_banned'=>true]);
        $this->assertSame(0,$delete($owner->id,$reply));
        DB::table('users')->where('id',$owner->id)->update(['is_banned'=>false]);
        DB::table('threads')->where('id',$thread)->update(['is_locked'=>true]);
        $this->assertSame(0,$delete($owner->id,$reply));
        DB::table('users')->where('id',$moderator->id)->update(['role'=>'member']);
        $this->assertSame(0,$delete($moderator->id,$reply));
        DB::table('users')->where('id',$admin->id)->update(['is_banned'=>true]);
        $this->assertSame(0,$delete($admin->id,$reply));
        DB::table('users')->where('id',$admin->id)->update(['is_banned'=>false]);
        $this->assertSame(0,$delete($admin->id,$first));
        $this->assertSame(1,$delete($admin->id,$reply));
        $this->assertDatabaseHas('posts',['id'=>$first]);
        $this->assertDatabaseMissing('posts',['id'=>$reply]);
    }
}
