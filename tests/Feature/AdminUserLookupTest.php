<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Requires complete PHP/SQLite + HTTP kernel; not covered by host SQL smoke. */
class AdminUserLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('u', 32)),
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function account(int $number, string $name, string $role = 'member', bool $banned = false): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $name, 'email' => 'user-'.$number.'@example.test',
            'password' => 'UnpublishedTestPassword!'.$number,
            'role' => $role, 'is_banned' => $banned, 'color' => 'violet',
        ])->save();
        return $user;
    }

    public function test_only_admin_can_find_and_unban_an_account_older_than_the_latest_100(): void
    {
        $admin = $this->account(1, 'Owner', 'admin');
        $veteran = $this->account(2, 'Veteran member', 'member', true);
        for ($i = 3; $i <= 114; $i++) {
            DB::table('users')->insert([
                'name' => 'Filler '.$i, 'email' => 'user-'.$i.'@example.test',
                'password' => 'unused-hash', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->getJson('/api/admin/users/search?q=Veteran')->assertUnauthorized();
        $member = $this->account(115, 'Ordinary member');
        $this->actingAs($member)->getJson('/api/admin/users/search?q=Veteran')->assertForbidden();
        $this->actingAs($admin);
        $this->assertNotContains($veteran->id, array_column($this->getJson('/api/admin')->assertOk()->json('users'), 'id'));
        $found = $this->getJson('/api/admin/users/search?q=Veteran')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $veteran->id)
            ->assertJsonPath('data.0.is_banned', true);
        $this->assertSame(['id','name','email','role','is_banned','created_at'], array_keys($found->json('data.0')));
        $this->getJson('/api/admin/users/search?q=%23'.$veteran->id)
            ->assertOk()->assertJsonPath('data.0.id', $veteran->id);
        $this->getJson('/api/admin/users/search?q=USER-2%40EXAMPLE.TEST')
            ->assertOk()->assertJsonPath('data.0.id', $veteran->id);
        $this->patchJson('/api/admin/users/'.$veteran->id, ['is_banned' => false])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $veteran->id, 'is_banned' => false]);
        // Searching cannot bypass the pre-existing protection of administrator accounts.
        $this->getJson('/api/admin/users/search?q=%23'.$admin->id)->assertOk()
            ->assertJsonPath('data.0.role', 'admin');
        $this->patchJson('/api/admin/users/'.$admin->id, ['is_banned' => true])->assertUnprocessable();
    }

    public function test_duplicate_names_special_characters_validation_and_bounded_response(): void
    {
        $admin = $this->account(1, 'Owner', 'admin');
        $a = $this->account(2, 'Echo');
        $b = $this->account(3, 'Echo');
        $percent = $this->account(4, '100% match');
        $this->account(5, '1000 match');
        $underscore = $this->account(6, 'nick_ever');
        $this->account(7, 'nickXever');
        $this->account(8, 'hello!world');
        for ($i = 9; $i <= 50; $i++) $this->account($i, 'Popular '.$i);
        $this->actingAs($admin);
        $this->getJson('/api/admin/users/search?q=Echo')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $b->id)
            ->assertJsonPath('data.1.id', $a->id);
        $this->getJson('/api/admin/users/search?q=100%25')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $percent->id);
        $this->getJson('/api/admin/users/search?q=nick_')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $underscore->id);
        $this->getJson('/api/admin/users/search?q=hello!')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/users/search?q=Popular')->assertOk()->assertJsonCount(20, 'data');
        $this->getJson('/api/admin/users/search?q=%20%20')->assertUnprocessable();
        $this->getJson('/api/admin/users/search?q=x')->assertUnprocessable();
    }
}
