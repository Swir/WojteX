<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Requires full Laravel HTTP and PDO; standalone middleware smoke is separate. */
class AdminMiddlewareLiveAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('v', 32))]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function account(string $name, string $role = 'member'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '-', $name)).'@example.test',
            'password' => 'NonProductionTestPassword2026!',
            'role' => $role, 'is_banned' => false, 'color' => 'violet',
        ])->save();
        return $user;
    }

    public function test_a_cached_administrator_cannot_read_private_admin_data_or_mutate_settings_after_demotion(): void
    {
        $admin = $this->account('Cached Admin', 'admin');
        $victim = $this->account('Private Target');
        $this->actingAs($admin);

        // Only the database row changes. Session's in-memory User still says admin.
        DB::table('users')->where('id', $admin->id)->update(['role' => 'member']);
        $this->assertSame('admin', $admin->role);

        $this->getJson('/api/admin/users/search?q=Private')
            ->assertForbidden()->assertDontSee($victim->email);
        $this->getJson('/api/admin')->assertForbidden();
        $this->patchJson('/api/admin/settings', ['registration_open' => false])->assertForbidden();
        $this->assertDatabaseMissing('settings', ['key' => 'registration_open']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'settings.updated']);
        $this->postJson('/api/admin/categories', ['name' => 'Unauthorized room'])
            ->assertForbidden();
        $this->assertDatabaseMissing('categories', ['name' => 'Unauthorized room']);
    }

    public function test_live_ban_and_deleted_actor_revoke_access_even_if_session_remains_admin(): void
    {
        $admin = $this->account('Ban Admin', 'admin');
        $this->actingAs($admin);
        DB::table('users')->where('id', $admin->id)->update(['is_banned' => true]);
        $this->assertFalse((bool) $admin->is_banned);
        $this->getJson('/api/admin/users/search?q=Ban')->assertForbidden();

        // Use a separate session object for a removed administrator.
        $removed = $this->account('Removed Admin', 'admin');
        $this->actingAs($removed);
        DB::table('users')->where('id', $removed->id)->delete();
        $this->getJson('/api/admin')->assertForbidden();
    }

    public function test_live_administrator_remains_authorized_for_read_and_write(): void
    {
        $admin = $this->account('Current Admin', 'admin');
        $this->actingAs($admin);
        $this->getJson('/api/admin/users/search?q=Current')->assertOk()
            ->assertJsonPath('data.0.email', $admin->email);
        $this->patchJson('/api/admin/settings', ['registration_open' => false])->assertOk();
        $this->assertDatabaseHas('settings', ['key' => 'registration_open', 'value' => 'false']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
    }
}
