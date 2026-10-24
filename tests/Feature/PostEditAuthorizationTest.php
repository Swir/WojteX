<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PostEditQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Integration tests: require working PHP PDO/SQLite and HTTP middleware. */
class PostEditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('e', 32)),
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        DB::table('settings')->insert(['key'=>'setup_complete','value'=>'true']);
    }

    private function account(string $name, string $role='member'): User
    {
        $user=new User();
        $user->forceFill(['name'=>$name,'email'=>strtolower($name).'@example.test',
            'password'=>'LongPasswordTest#2026','role'=>$role,'is_banned'=>false,
            'color'=>'violet'])->save();
        return $user;
    }

    private function topic(User $owner): array
    {
        $category=DB::table('categories')->insertGetId([
            'name'=>'General', 'slug'=>'general-'.uniqid(), 'description'=>'',
            'color'=>'violet', 'icon'=>'chat', 'parent_id'=>null, 'sort_order'=>0,
            'is_private'=>false,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $thread=DB::table('threads')->insertGetId([
            'category_id'=>$category,'user_id'=>$owner->id,
            'title'=>'Editable topic','slug'=>'editable-topic',
            'views'=>0,'is_pinned'=>false,'is_locked'=>false,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $post=DB::table('posts')->insertGetId([
            'thread_id'=>$thread,'user_id'=>$owner->id,'body'=>'Original text',
            'body_format'=>'markdown','created_at'=>now(),'updated_at'=>now(),
        ]);
        return [$thread,$post];
    }

    public function test_http_owner_edit_and_live_thread_lock(): void
    {
        $owner=$this->account('Alice');
        [$thread,$post]=$this->topic($owner);
        $this->actingAs($owner);
        $this->patchJson('/api/posts/'.$post,['body'=>'Revised original post'])->assertOk();
        $this->assertSame('Revised original post',DB::table('posts')->find($post)->body);
        DB::table('threads')->where('id',$thread)->update(['is_locked'=>true]);
        $this->patchJson('/api/posts/'.$post,['body'=>'Should not be published'])->assertForbidden();
        $this->assertSame('Revised original post',DB::table('posts')->find($post)->body);
    }

    public function test_live_role_and_ban_guarded_by_same_update_statement(): void
    {
        $owner=$this->account('Bob');
        [, $post]=$this->topic($owner);
        $moderator=$this->account('Carol','moderator');
        $admin=$this->account('Dave','admin');
        $other=$this->account('Eva');

        $edit=static fn(int $actor): int => PostEditQuery::eligible($post,$actor)
            ->update(['body'=>'New staff text','updated_at'=>now()->addMinute()]);
        $this->assertSame(0,$edit($other->id));
        $this->assertSame(1,$edit($moderator->id));
        DB::table('users')->where('id',$moderator->id)->update(['role'=>'member']);
        $this->assertSame(0,$edit($moderator->id));
        DB::table('users')->where('id',$admin->id)->update(['is_banned'=>true]);
        $this->assertSame(0,$edit($admin->id));
        DB::table('users')->where('id',$admin->id)->update(['is_banned'=>false]);
        $this->assertSame(1,$edit($admin->id));
        $this->assertSame('New staff text',DB::table('posts')->find($post)->body);
    }
}
