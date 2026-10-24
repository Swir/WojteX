<?php

namespace Tests\Feature;

use App\Http\Controllers\AuthController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Written for genuine Laravel/PDO qualification. Cannot run without PDO SQLite. */
class ProfileLiveWriteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key'=>'base64:'.base64_encode(str_repeat('r', 32))]);
        DB::table('settings')->insert(['key'=>'setup_complete','value'=>'true']);
    }

    private function newMember(): User
    {
        $account=new User();
        $account->forceFill([
            'name'=>'Before','email'=>'profile-live@example.test','password'=>'ValidPassword2026!',
            'role'=>'member','bio'=>'Original biography','is_banned'=>false,'color'=>'violet',
            'allow_private_messages'=>true,
        ]);
        $account->save();
        return $account;
    }

    public function test_cached_active_session_cannot_edit_after_db_ban(): void
    {
        $cached=$this->newMember();
        $this->assertFalse((bool)$cached->is_banned);
        // Simulates a committed ban AFTER the session's User has been read.
        DB::table('users')->where('id',$cached->id)->update(['is_banned'=>true]);
        $request=Request::create('/api/profile','PATCH',[
            'name'=>'Banned rename','bio'=>'Banned biography','allow_private_messages'=>false,
        ]);
        $request->setUserResolver(static fn()=> $cached);
        try {
            app(AuthController::class)->profile($request);
            $this->fail('Cached unbanned session was allowed to write after a database ban');
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $actual=$cached->fresh();
        $this->assertSame('Before',$actual->name);
        $this->assertSame('Original biography',$actual->bio);
        $this->assertTrue((bool)$actual->allow_private_messages);
        $this->assertTrue((bool)$actual->is_banned);
    }

    public function test_active_profile_update_does_not_reset_omitted_pm_preference(): void
    {
        $account=$this->newMember();
        $this->actingAs($account)->patchJson('/api/profile',[
            'name'=>'Before','bio'=>'Changed bio','allow_private_messages'=>false,
        ])->assertOk()->assertJsonPath('user.allow_private_messages',false);
        $this->patchJson('/api/profile',[
            'name'=>'Renamed active','bio'=>'Changed again',
        ])->assertOk()->assertJsonPath('user.allow_private_messages',false);
        $fresh=$account->fresh();
        $this->assertSame('Renamed active',$fresh->name);
        $this->assertFalse((bool)$fresh->allow_private_messages);
    }

    public function test_active_identical_update_is_allowed_even_with_zero_affected_rows(): void
    {
        $account=$this->newMember();
        $this->actingAs($account)->patchJson('/api/profile',[
            'name'=>'Before','bio'=>'Original biography',
        ])->assertOk()->assertJsonPath('user.name','Before');
    }
}
