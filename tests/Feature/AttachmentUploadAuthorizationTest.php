<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AttachmentInsertQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Laravel HTTP / PHP PDO gate; DO NOT count as passed without extensions. */
class AttachmentUploadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('a',32))]);
        DB::table('settings')->insert(['key'=>'setup_complete','value'=>'true']);
        Storage::fake('local');
    }

    private function account(string $name,string $role='member'): User
    {
        $u=new User();
        $u->forceFill([
            'name'=>$name,'email'=>strtolower($name).'@example.test',
            'password'=>'TestOnlyOwnerPassword','role'=>$role,
            'is_banned'=>false,'color'=>'violet',
        ])->save();
        return $u;
    }

    private function createPostFixture(User $author): array
    {
        $category=DB::table('categories')->insertGetId([
            'name'=>'General','slug'=>'general-attachment','description'=>'',
            'color'=>'violet','icon'=>'chat','parent_id'=>null,
            'sort_order'=>0,'is_private'=>false,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $thread=DB::table('threads')->insertGetId([
            'category_id'=>$category,'user_id'=>$author->id,
            'title'=>'Attachments example','slug'=>'attachments-example',
            'views'=>0,'is_pinned'=>false,'is_locked'=>false,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $post=DB::table('posts')->insertGetId([
            'thread_id'=>$thread,'user_id'=>$author->id,
            'body'=>'Example post','body_format'=>'markdown',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return [$thread,$post];
    }

    private function send(int $post)
    {
        return $this->post('/api/posts/'.$post.'/attachments',[
            'file'=>UploadedFile::fake()->create('safe.txt',1,'text/plain'),
        ],['Accept'=>'application/json']);
    }

    public function test_owner_can_upload_five_attachments_but_not_six(): void
    {
        $owner=$this->account('Writer');
        [, $post]=$this->createPostFixture($owner);
        $this->actingAs($owner);
        for($i=0;$i<5;$i++) $this->send($post)->assertCreated();
        $this->assertDatabaseCount('attachments',5);
        $this->send($post)->assertUnprocessable();
        $this->assertDatabaseCount('attachments',5);
        $this->assertCount(5,Storage::disk('local')->allFiles('attachments'));
    }

    public function test_stale_session_ban_and_moderator_demotion_cannot_upload_or_leave_file(): void
    {
        $owner=$this->account('Original');
        [$thread,$post]=$this->createPostFixture($owner);
        $this->actingAs($owner);
        // Only the database row changes: the authenticated User object remains
        // stale, reproducing the preflight-vs-INSERT authorization boundary.
        DB::table('users')->where('id',$owner->id)->update(['is_banned'=>true]);
        $this->send($post)->assertForbidden();
        $this->assertDatabaseCount('attachments',0);
        $this->assertSame([],Storage::disk('local')->allFiles('attachments'));

        $mod=$this->account('Staff','moderator');
        $this->actingAs($mod);
        DB::table('users')->where('id',$mod->id)->update(['role'=>'member']);
        $this->send($post)->assertForbidden();
        $this->assertDatabaseCount('attachments',0);
        $this->assertSame([],Storage::disk('local')->allFiles('attachments'));
    }

    public function test_sql_guard_rechecks_lock_ownership_and_quota(): void
    {
        $owner=$this->account('Tester');
        [$thread,$post]=$this->createPostFixture($owner);
        $send=static fn() => DB::table('attachments')->insertUsing(
            ['post_id','user_id','path','name','mime','size','created_at','updated_at'],
            AttachmentInsertQuery::eligible($post,$thread,$owner->id,'attachments/test.txt',
                'test.txt','text/plain',8,'2026-10-23 20:00:00')
        );
        DB::table('threads')->where('id',$thread)->update(['is_locked'=>true]);
        $this->assertSame(0,$send());
        DB::table('threads')->where('id',$thread)->update(['is_locked'=>false]);
        $this->assertSame(1,$send());
        for($i=2;$i<=5;$i++){
            $this->assertSame(1,$send());
        }
        $this->assertSame(0,$send());
        $this->assertDatabaseCount('attachments',5);
    }
}
