<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Native Laravel HTTP + PHP PDO checks. NOT executable without required PHP extensions. */
class RegistrationLiveSettingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'hashing.driver' => 'bcrypt', 'hashing.bcrypt.rounds' => 4,
        ]);
        DB::table('settings')->insert([
            ['key'=>'setup_complete','value'=>'true'],
            ['key'=>'registration_open','value'=>'true'],
        ]);
    }

    private function input(): array
    {
        return [
            'name'=>'New member', 'email'=>'new@example.test',
            'password'=>'Strong-password-2026!',
            'password_confirmation'=>'Strong-password-2026!',
        ];
    }

    public function test_still_open_registration_creates_hashed_member_and_session(): void
    {
        $this->postJson('/api/auth/register', $this->input())->assertCreated()
            ->assertJsonPath('user.role','member');
        $user=DB::table('users')->where('email','new@example.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue(password_verify('Strong-password-2026!', $user->password));
        $this->assertSame('member',$user->role);
    }

    public function test_closing_registration_during_expensive_hash_disallows_account_and_session(): void
    {
        Hash::shouldReceive('isHashed')->andReturn(false);
        Hash::shouldReceive('make')->once()->andReturnUsing(function (string $password): string {
            // Happens after the initial Forum::settings() read, before users INSERT.
            DB::table('settings')->where('key','registration_open')->update(['value'=>'false']);
            return password_hash($password, PASSWORD_BCRYPT, ['cost'=>4]);
        });
        $this->postJson('/api/auth/register', $this->input())->assertForbidden();
        $this->assertDatabaseCount('users',0);
    }

    public function test_missing_switch_keeps_historic_default_open_but_malformed_switch_fails_closed(): void
    {
        DB::table('settings')->where('key','registration_open')->delete();
        $this->postJson('/api/auth/register', $this->input())->assertCreated();
        // A separate HTTP session using the same credentials must not be
        // enough to create a second account when the persisted switch is invalid.
        DB::table('settings')->insert(['key'=>'registration_open','value'=>'null']);
        $this->postJson('/api/auth/register', array_merge($this->input(), ['email'=>'second@example.test']))
            ->assertForbidden();
        $this->assertDatabaseCount('users',1);
    }
}
