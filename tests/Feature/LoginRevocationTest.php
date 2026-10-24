<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Laravel HTTP/PDO regression: intentionally NOT executed on the incomplete CLI runtime. */
class LoginRevocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('L', 32)),
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 4,
        ]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function account(): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Login race fixture',
            'email' => 'race@example.test',
            'password' => 'InitialPassword2026!',
            'role' => 'member',
            'color' => 'violet',
            'is_banned' => false,
        ])->save();
        return $user;
    }

    public function test_ban_committed_during_hash_check_prevents_login(): void
    {
        $user = $this->account();
        Hash::partialMock()->shouldReceive('check')->once()->andReturnUsing(
            static function (string $plain, string $hash) use ($user): bool {
                $valid = password_verify($plain, $hash);
                DB::table('users')->where('id', $user->id)->update(['is_banned' => true]);
                return $valid;
            }
        );
        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'InitialPassword2026!',
        ])->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_password_rotation_during_hash_check_invalidates_old_credential(): void
    {
        $user = $this->account();
        $newHash = password_hash('NewPassword2026!', PASSWORD_BCRYPT, ['cost' => 4]);
        Hash::partialMock()->shouldReceive('check')->once()->andReturnUsing(
            static function (string $plain, string $hash) use ($user, $newHash): bool {
                $valid = password_verify($plain, $hash);
                DB::table('users')->where('id', $user->id)->update([
                    'password' => $newHash, 'remember_token' => 'rotated-for-test',
                ]);
                return $valid;
            }
        );
        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'InitialPassword2026!',
        ])->assertUnprocessable();
        $this->assertGuest();
        $this->assertSame($newHash, DB::table('users')->where('id', $user->id)->value('password'));
    }

    public function test_response_uses_fresh_role_after_promotion(): void
    {
        $user = $this->account();
        $realHasher = Hash::getFacadeRoot();
        Hash::partialMock()->shouldReceive('needsRehash')->once()
            ->andReturnUsing(static fn (string $hash): bool => $realHasher->needsRehash($hash));
        Hash::partialMock()->shouldReceive('check')->once()->andReturnUsing(
            static function (string $plain, string $hash) use ($user): bool {
                $valid = password_verify($plain, $hash);
                DB::table('users')->where('id', $user->id)->update(['role' => 'admin']);
                return $valid;
            }
        );
        $this->postJson('/api/auth/login', [
            'email' => $user->email, 'password' => 'InitialPassword2026!',
        ])->assertOk()->assertJsonPath('user.role', 'admin');
        $this->assertAuthenticatedAs($user);
    }
}
