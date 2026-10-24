<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Requires PHP PDO SQLite / full PHPUnit; intentionally not claimed by host smoke. */
class GroupMemberLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('l', 32)),
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function account(string $name, int $i, string $role = 'member'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name, 'email' => 'account-'.$i.'@example.test',
            'password' => 'NoRealPassword2026!'.$i, 'role' => $role,
            'is_banned' => false, 'color' => 'violet',
        ])->save();
        return $user;
    }

    public function test_admin_can_find_and_assign_accounts_older_than_recent_100(): void
    {
        $admin = $this->account('Site Owner', 1, 'admin');
        $old = $this->account('Veteran Player', 2);
        // Dashboard only shows newest 100; this account must remain manageable.
        for ($i = 3; $i <= 114; $i++) {
            DB::table('users')->insert([
                'name' => 'Filler '.$i, 'email' => 'account-'.$i.'@example.test',
                'password' => 'unused-hash', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->getJson('/api/admin/groups/users?q=Veteran')->assertUnauthorized();
        $this->actingAs($old);
        $this->getJson('/api/admin/groups/users?q=Veteran')->assertForbidden();
        $this->actingAs($admin);
        $groupId = $this->postJson('/api/admin/groups', ['name' => 'Founding contributors'])
            ->assertCreated()->json('id');
        $response = $this->getJson('/api/admin/groups/users?q=Veteran')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->id);
        $this->assertArrayNotHasKey('email', $response->json('data.0'));
        $this->assertArrayNotHasKey('password', $response->json('data.0'));
        $this->getJson('/api/admin/groups/users?q=%23'.$old->id)
            ->assertOk()->assertJsonPath('data.0.id', $old->id);
        $this->putJson('/api/admin/groups/'.$groupId.'/users/'.$old->id, ['enabled' => true])
            ->assertOk();
        $this->getJson('/api/admin/groups')->assertOk()
            ->assertJsonPath('groups.0.member_count', 1);
        $this->getJson('/api/admin/groups/users?q=Veteran')->assertOk()
            ->assertJsonPath('data.0.group_ids.0', $groupId);
        $this->putJson('/api/admin/groups/'.$groupId.'/users/'.$old->id, ['enabled' => false])
            ->assertOk();
        $this->getJson('/api/admin/groups')->assertOk()->assertJsonPath('groups.0.member_count', 0);
    }

    public function test_duplicate_names_wildcards_and_response_limits_are_safe(): void
    {
        $admin = $this->account('Site Owner', 1, 'admin');
        $a = $this->account('Echo', 2);
        $b = $this->account('Echo', 3);
        $literal = $this->account('100% match', 4);
        $this->account('1000 match', 5);
        $underscore = $this->account('nick_ever', 6);
        $this->account('nickXever', 7);
        $this->account('hello!world', 8);
        for ($i = 9; $i <= 45; $i++) $this->account('Popular '.$i, $i);
        $this->actingAs($admin);
        $this->getJson('/api/admin/groups/users?q=Echo')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $a->id)
            ->assertJsonPath('data.1.id', $b->id);
        $this->getJson('/api/admin/groups/users?q=100%25')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $literal->id);
        $this->getJson('/api/admin/groups/users?q=nick_')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $underscore->id);
        $this->getJson('/api/admin/groups/users?q=hello!')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/groups/users?q=Popular')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/admin/groups/users?q=%20%20')->assertUnprocessable();
        $this->getJson('/api/admin/groups/users?q=x')->assertUnprocessable();
    }
}
