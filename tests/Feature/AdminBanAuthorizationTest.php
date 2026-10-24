<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Real Laravel HTTP / PDO test when extensions are installed; NOT run on this host. */
class AdminBanAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('b',32))]);
        DB::table('settings')->insert(['key'=>'setup_complete','value'=>'true']);
    }

    private function account(int $number,string $role='member',bool $banned=false): User
    {
        $u=new User();
        $u->forceFill([
            'name'=>'Admin Ban Fixture '.$number,
            'email'=>'admin-ban-'.$number.'@example.test',
            'password'=>'test-only-strong-password-'.$number,
            'role'=>$role,'is_banned'=>$banned,'color'=>'violet',
        ])->save();
        return $u;
    }

    public function test_demoted_cached_admin_cannot_ban_or_audit(): void
    {
        $admin=$this->account(1,'admin');$target=$this->account(2);
        $this->actingAs($admin);
        DB::table('users')->where('id',$admin->id)->update(['role'=>'member']);
        $this->patchJson('/api/admin/users/'.$target->id,['is_banned'=>true])->assertForbidden();
        $this->assertDatabaseHas('users',['id'=>$target->id,'is_banned'=>false]);
        $this->assertDatabaseCount('audit_logs',0);
    }

    public function test_banned_cached_admin_cannot_ban_or_audit(): void
    {
        $admin=$this->account(3,'admin');$target=$this->account(4);
        $this->actingAs($admin);
        DB::table('users')->where('id',$admin->id)->update(['is_banned'=>true]);
        $this->patchJson('/api/admin/users/'.$target->id,['is_banned'=>true])->assertForbidden();
        $this->assertDatabaseHas('users',['id'=>$target->id,'is_banned'=>false]);
        $this->assertDatabaseCount('audit_logs',0);
    }

    public function test_admin_cannot_ban_admin_or_self_but_can_ban_member(): void
    {
        $admin=$this->account(5,'admin');$otherAdmin=$this->account(6,'admin');$member=$this->account(7);
        $this->actingAs($admin);
        $this->patchJson('/api/admin/users/'.$admin->id,['is_banned'=>true])->assertUnprocessable();
        $this->patchJson('/api/admin/users/'.$otherAdmin->id,['is_banned'=>true])->assertUnprocessable();
        $this->patchJson('/api/admin/users/'.$member->id,['is_banned'=>true])->assertOk();
        $this->assertDatabaseHas('users',['id'=>$member->id,'is_banned'=>true]);
        $this->assertDatabaseHas('users',['id'=>$otherAdmin->id,'is_banned'=>false]);
        $this->assertDatabaseCount('audit_logs',1);
        $this->patchJson('/api/admin/users/'.$member->id,['is_banned'=>false])->assertOk();
        $this->assertDatabaseHas('users',['id'=>$member->id,'is_banned'=>false]);
    }
}
