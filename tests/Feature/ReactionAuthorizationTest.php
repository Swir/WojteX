<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ReactionToggleQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Native Laravel HTTP/PDO gates; NOT run when dom/mbstring/pdo_sqlite are absent. */
class ReactionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('r',32)),
            'hashing.driver'=>'argon2id',
            'hashing.argon.memory'=>1024,
            'hashing.argon.time'=>1,
            'hashing.argon.threads'=>1,
        ]);
        DB::table('settings')->insert(['key'=>'setup_complete','value'=>'true']);
    }

    private function member(string $name): User
    {
        $user=new User();
        $user->forceFill([
            'name'=>$name,'email'=>strtolower($name).'@example.test',
            'password'=>'StrongTestPassword#2026','role'=>'member',
            'is_banned'=>false,'color'=>'violet',
        ])->save();
        return $user;
    }

    private function createPostFixture(User $owner): int
    {
        $category=DB::table('categories')->insertGetId([
            'name'=>'General','slug'=>'general','description'=>'',
            'color'=>'violet','icon'=>'chat','sort_order'=>0,
            'is_private'=>false,'parent_id'=>null,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $thread=DB::table('threads')->insertGetId([
            'category_id'=>$category,'user_id'=>$owner->id,'title'=>'Public topic',
            'slug'=>'public-topic','is_locked'=>false,'is_pinned'=>false,
            'views'=>0,'created_at'=>now(),'updated_at'=>now(),
        ]);
        return DB::table('posts')->insertGetId([
            'thread_id'=>$thread,'user_id'=>$owner->id,'body'=>'Public post',
            'body_format'=>'markdown','created_at'=>now(),'updated_at'=>now(),
        ]);
    }

    public function test_http_active_member_can_toggle_and_author_cannot_self_react(): void
    {
        $author=$this->member('Author');$reader=$this->member('Reader');$post=$this->createPostFixture($author);
        $this->actingAs($author)->postJson('/api/posts/'.$post.'/reaction')->assertStatus(422);
        $this->actingAs($reader)->postJson('/api/posts/'.$post.'/reaction')
            ->assertOk()->assertJsonPath('reacted',true)->assertJsonPath('reactions_count',1);
        $this->postJson('/api/posts/'.$post.'/reaction')
            ->assertOk()->assertJsonPath('reacted',false)->assertJsonPath('reactions_count',0);
    }

    public function test_live_sql_guard_denies_ban_and_author_switch_at_both_mutations(): void
    {
        $author=$this->member('Author');$reader=$this->member('Reader');$post=$this->createPostFixture($author);
        $insert=static fn() => DB::table('reactions')->insertOrIgnoreUsing(
            ['post_id','user_id','created_at','updated_at'],
            ReactionToggleQuery::eligibleInsert($post,(int)$reader->id,now()->toDateTimeString())
        );
        $delete=static fn() => ReactionToggleQuery::eligibleDelete($post,(int)$reader->id)->delete();
        DB::table('users')->where('id',$reader->id)->update(['is_banned'=>true]);
        $this->assertSame(0,$insert());
        $this->assertDatabaseCount('reactions',0);
        DB::table('users')->where('id',$reader->id)->update(['is_banned'=>false]);
        $this->assertSame(1,$insert());
        DB::table('users')->where('id',$reader->id)->update(['is_banned'=>true]);
        $this->assertSame(0,$delete());
        $this->assertDatabaseCount('reactions',1);
        DB::table('users')->where('id',$reader->id)->update(['is_banned'=>false]);
        DB::table('posts')->where('id',$post)->update(['user_id'=>$reader->id]);
        $this->assertSame(0,$delete());
        DB::table('posts')->where('id',$post)->update(['user_id'=>$author->id]);
        $this->assertSame(1,$delete());
        $this->assertDatabaseCount('reactions',0);
    }
}
