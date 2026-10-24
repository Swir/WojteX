<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ThreadModerateQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real Laravel HTTP + PDO integration; requires complete PHP extensions. */
class ThreadModerateAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('m', 32))]);
        DB::table('settings')->insert(['key'=>'setup_complete','value'=>'true']);
    }

    private function account(string $name, string $role='member'): User
    {
        $user=new User();
        $user->forceFill([
            'name'=>$name,'email'=>strtolower($name).'@example.test',
            'password'=>'NotARealOwnerPassword#2026','role'=>$role,
            'is_banned'=>false,'color'=>'violet',
        ])->save();
        return $user;
    }

    private function topic(User $author): int
    {
        $category=DB::table('categories')->insertGetId([
            'name'=>'General','slug'=>'general-moderation','description'=>'',
            'color'=>'violet','icon'=>'chat','parent_id'=>null,
            'sort_order'=>0,'is_private'=>false,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $id=DB::table('threads')->insertGetId([
            'category_id'=>$category,'user_id'=>$author->id,
            'title'=>'Topic for moderation','slug'=>'topic-moderation',
            'views'=>0,'is_pinned'=>false,'is_locked'=>false,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('posts')->insert([
            'thread_id'=>$id,'user_id'=>$author->id,'body'=>'Original thread body',
            'body_format'=>'markdown','created_at'=>now(),'updated_at'=>now(),
        ]);
        return $id;
    }

    public function test_http_moderator_updates_thread_but_member_cannot(): void
    {
        $owner=$this->account('Owner');
        $mod=$this->account('Moderator','moderator');
        $member=$this->account('Reader');
        $topic=$this->topic($owner);
        $this->actingAs($mod)->patchJson('/api/threads/'.$topic,[
            'is_locked'=>true,'is_pinned'=>true,
        ])->assertOk();
        // Idempotent repeated PATCH must remain permitted even on MySQL where
        // UPDATE may return zero affected rows for unchanged values.
        $this->actingAs($mod)->patchJson('/api/threads/'.$topic,[
            'is_locked'=>true,'is_pinned'=>true,
        ])->assertOk();
        $this->assertSame(1,(int)DB::table('threads')->find($topic)->is_locked);
        $this->actingAs($member)->patchJson('/api/threads/'.$topic,[
            'is_locked'=>false,
        ])->assertForbidden();
        $this->assertSame(1,(int)DB::table('threads')->find($topic)->is_locked);
    }

    public function test_same_update_guard_checks_current_role_ban_and_existing_account(): void
    {
        $owner=$this->account('Writer');
        $mod=$this->account('Mod','moderator');
        $admin=$this->account('Admin','admin');
        $topic=$this->topic($owner);
        $moderate=static fn(int $actor, bool $value) =>
            ThreadModerateQuery::eligible($topic,$actor)->update([
                'is_locked'=>$value,'is_pinned'=>$value,
            ]);
        DB::table('users')->where('id',$mod->id)->update(['role'=>'member']);
        $this->assertSame(0,$moderate($mod->id,true));
        $this->assertSame(0,(int)DB::table('threads')->find($topic)->is_pinned);
        DB::table('users')->where('id',$mod->id)->update(['role'=>'moderator','is_banned'=>true]);
        $this->assertSame(0,$moderate($mod->id,true));
        DB::table('users')->where('id',$mod->id)->update(['is_banned'=>false]);
        $this->assertSame(1,$moderate($mod->id,true));
        DB::table('users')->where('id',$admin->id)->update(['is_banned'=>true]);
        $this->assertSame(0,$moderate($admin->id,false));
        $this->assertSame(1,(int)DB::table('threads')->find($topic)->is_pinned);
        DB::table('users')->where('id',$admin->id)->update(['is_banned'=>false]);
        $this->assertSame(1,$moderate($admin->id,false));
        $this->assertSame(0,(int)DB::table('threads')->find($topic)->is_locked);
    }
}
