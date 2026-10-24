<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ShoutboxDeleteQuery;
use App\Services\ShoutboxWriteQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Requires an actual Laravel PHP PDO driver. NOT executed in partial PHP CLI.
 * Mutate the authoritative plugin row after the hypothetical request preflight.
 */
class ShoutboxLivePluginMutationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mutation_sql_fails_closed_after_shoutbox_is_disabled(): void
    {
        $user = User::factory()->create(['role'=>'member','is_banned'=>false]);
        $time='2026-10-24 05:25:00';
        DB::table('plugin_states')->insert([
            'key'=>'shoutbox', 'enabled'=>true, 'created_at'=>$time,'updated_at'=>$time,
        ]);
        DB::table('shouts')->insert(['user_id'=>$user->id, 'body'=>'Keep existing message',
            'created_at'=>$time, 'updated_at'=>$time]);
        $shoutId=(int) DB::table('shouts')->value('id');

        // Authorization originally saw enabled=true; disable after that read.
        DB::table('plugin_states')->where('key','shoutbox')->update(['enabled'=>false]);
        $written=DB::table('shouts')->insertUsing(
            ['user_id','body','created_at','updated_at'],
            ShoutboxWriteQuery::eligible((int)$user->id, 'This must not appear', $time)
        );
        $removed=ShoutboxDeleteQuery::eligible($shoutId,(int)$user->id)->delete();
        $this->assertSame(0,$written);
        $this->assertSame(0,$removed);
        $this->assertDatabaseHas('shouts', ['id'=>$shoutId,'body'=>'Keep existing message']);
        $this->assertDatabaseMissing('shouts', ['body'=>'This must not appear']);

        // Turning the module back on keeps ordinary owner posting/deleting.
        DB::table('plugin_states')->where('key','shoutbox')->update(['enabled'=>true]);
        $this->assertSame(1,DB::table('shouts')->insertUsing(
            ['user_id','body','created_at','updated_at'],
            ShoutboxWriteQuery::eligible((int)$user->id, 'Restored operation', $time)
        ));
        $this->assertSame(1,ShoutboxDeleteQuery::eligible($shoutId,(int)$user->id)->delete());
        $this->assertDatabaseMissing('shouts', ['id'=>$shoutId]);
    }
}
