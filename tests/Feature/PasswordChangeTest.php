<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/auth/password';
    private const ORIGINAL = 'SyntheticCurrent2026!';
    private const REPLACEMENT = 'SyntheticReplacement2026!';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'hashing.driver' => 'bcrypt',
            'hashing.bcrypt.rounds' => 4,
        ]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
    }

    private function user(): User
    {
        return User::create(['name' => 'Password change member', 'email' => 'change@example.test', 'password' => self::ORIGINAL]);
    }

    private function payload(string $current = self::ORIGINAL, string $next = self::REPLACEMENT): array
    {
        return ['current_password' => $current, 'password' => $next, 'password_confirmation' => $next];
    }

    private function afterCurrentPasswordCheck(callable $change): void
    {
        $hasher = Hash::getFacadeRoot();
        $mock = Hash::partialMock();
        $mock->shouldReceive('make')->once()->andReturnUsing($hasher->make(...));
        $mock->shouldReceive('check')->once()->andReturnUsing(
            static function (string $plain, string $hash) use ($hasher, $change): bool {
                $matches = $hasher->check($plain, $hash);
                $change();
                return $matches;
            }
        );
    }

    public function test_changing_password_keeps_this_session_and_revokes_other_sessions_and_recovery_tokens(): void
    {
        $user = $this->user();
        $user->forceFill(['remember_token' => 'test-remember-token'])->save();
        $token = Password::broker()->createToken($user);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => self::ORIGINAL])->assertOk();
        $oldCsrf = $this->getJson('/api/bootstrap')->assertOk()->json('csrf_token');
        $oldSessionId = session()->getId();
        $oldFingerprint = session()->get('password_hash_web');
        $authKey = $this->app['auth']->guard()->getName();
        $this->assertNotEmpty($oldFingerprint);

        $response = $this->putJson(self::URL, $this->payload())->assertOk()->assertJsonStructure(['message', 'csrf_token']);
        $this->assertNotSame($oldCsrf, $response->json('csrf_token'));
        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertTrue(Hash::check(self::REPLACEMENT, $user->fresh()->password));
        $this->assertNotSame('test-remember-token', $user->fresh()->remember_token);
        $this->assertFalse(Password::broker()->tokenExists($user, $token));

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('csrf_token', $response->json('csrf_token'));

        // Model a second login that still presents the pre-change fingerprint.
        $this->withSession([$authKey => $user->id, 'password_hash_web' => $oldFingerprint]);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/bootstrap')->assertUnauthorized();
    }

    public function test_guest_wrong_current_password_and_invalid_replacements_cannot_change_credentials(): void
    {
        $user = $this->user();
        $originalHash = $user->password;
        $this->putJson(self::URL, $this->payload())->assertUnauthorized();
        $this->actingAs($user)->putJson(self::URL, $this->payload('IncorrectCurrent2026!'))
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $invalid = $this->payload();
        $invalid['password_confirmation'] = 'TypoInConfirmation2026!';
        $this->putJson(self::URL, $invalid)->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->putJson(self::URL, $this->payload(self::ORIGINAL, self::ORIGINAL))
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertSame($originalHash, $user->fresh()->password);
    }

    public function test_a_change_rejects_a_password_rotated_during_verification(): void
    {
        $user = $this->user();
        $hashFromOtherRequest = Hash::make('SyntheticConcurrent2026!');
        $this->afterCurrentPasswordCheck(static fn () => DB::table('users')->where('id', $user->id)
            ->update(['password' => $hashFromOtherRequest]));

        $this->actingAs($user)->putJson(self::URL, $this->payload())->assertStatus(409);
        $this->assertSame($hashFromOtherRequest, $user->fresh()->password);
    }

    public function test_a_ban_during_verification_leaves_credentials_and_recovery_token_untouched(): void
    {
        $user = $this->user();
        $originalHash = $user->password;
        Password::broker()->createToken($user);
        $this->afterCurrentPasswordCheck(static fn () => DB::table('users')->where('id', $user->id)
            ->update(['is_banned' => true]));

        $this->actingAs($user)->putJson(self::URL, $this->payload())->assertStatus(409);
        $this->assertSame($originalHash, $user->fresh()->password);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $user->email]);
    }
}
