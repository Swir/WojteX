<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Full Laravel HTTP/PDO test; NOT executed on extension-incomplete cloud PHP. */
class BookmarkLiveAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('b',32))]);
        DB::table('settings')->insert(['key'=>'setup_complete','value'=>'true']);
    }

    private function fixture(): array
    {
        $writer=new User();
        $writer->forceFill([
            'name'=>'Bookmark author', 'email'=>'bookmark-author@example.test',
            'password'=>'ExampleSecret2026!', 'role'=>'member','color'=>'violet','is_banned'=>false,
        ])->save();
        $reader=new User();
        $reader->forceFill([
            'name'=>'Bookmark reader', 'email'=>'bookmark-reader@example.test',
            'password'=>'ExampleSecret2026!', 'role'=>'member','color'=>'cyan','is_banned'=>false,
        ])->save();
        $category=DB::table('categories')->insertGetId([
            'name'=>'Public bookmarks', 'slug'=>'public-bookmarks', 'description'=>'',
            'color'=>'violet','icon'=>'code','sort_order'=>0,'is_private'=>false,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $thread=DB::table('threads')->insertGetId([
            'category_id'=>$category, 'user_id'=>$writer->id, 'title'=>'Bookmark test thread',
            'slug'=>'bookmark-test-thread','created_at'=>now(),'updated_at'=>now(),
        ]);
        DB::table('posts')->insert([
            'thread_id'=>$thread,'user_id'=>$writer->id, 'body'=>'First post',
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        return [$reader, $thread];
    }

    /** Ban is written after preflight's first bookmark EXISTS, before mutation SQL. */
    private function banAfterBookmarkPreflight(int $id): \Closure
    {
        $fired=false;
        DB::listen(function ($event) use ($id, &$fired) {
            $query=strtolower($event->sql);
            if ($fired || !str_contains($query,'bookmarks') || !str_contains($query,'exists')) return;
            $fired=true;
            DB::table('users')->where('id',$id)->update(['is_banned'=>true]);
        });
        return function () use (&$fired): bool { return $fired; };
    }

    public function test_ban_between_bookmark_preflight_and_insert_denies_new_row(): void
    {
        [$reader,$thread]=$this->fixture();
        $fired=$this->banAfterBookmarkPreflight($reader->id);
        $this->actingAs($reader)->postJson('/api/threads/'.$thread.'/bookmark')->assertForbidden();
        $this->assertTrue($fired(), 'The simulated ban must happen AFTER bookmark preflight');
        $this->assertDatabaseCount('bookmarks', 0);
    }

    public function test_ban_between_bookmark_preflight_and_delete_preserves_row(): void
    {
        [$reader,$thread]=$this->fixture();
        DB::table('bookmarks')->insert([
            'thread_id'=>$thread,'user_id'=>$reader->id,
            'created_at'=>now(),'updated_at'=>now(),
        ]);
        $fired=$this->banAfterBookmarkPreflight($reader->id);
        $this->actingAs($reader)->postJson('/api/threads/'.$thread.'/bookmark')->assertForbidden();
        $this->assertTrue($fired(), 'The simulated ban must happen AFTER bookmark preflight');
        $this->assertDatabaseHas('bookmarks',['thread_id'=>$thread,'user_id'=>$reader->id]);
    }

    public function test_active_account_can_toggle_and_duplicate_pairs_are_prevented(): void
    {
        [$reader,$thread]=$this->fixture();
        $this->actingAs($reader)->postJson('/api/threads/'.$thread.'/bookmark')
            ->assertOk()->assertJsonPath('bookmarked', true);
        $this->assertDatabaseCount('bookmarks',1);
        $this->postJson('/api/threads/'.$thread.'/bookmark')
            ->assertOk()->assertJsonPath('bookmarked',false);
        $this->assertDatabaseCount('bookmarks',0);
    }
}
