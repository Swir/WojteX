<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VeyraPasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** Real HTTP flow, with outgoing mail intercepted by Laravel's Notification fake. */
class PasswordRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('t', 32)),
            'app.url' => 'http://localhost:8080',
            'mail.default' => 'array',
            'hashing.driver' => 'argon2id',
            'hashing.argon.memory' => 1024,
            'hashing.argon.time' => 1,
            'hashing.argon.threads' => 1,
        ]);
        DB::table('settings')->insert(['key' => 'setup_complete', 'value' => 'true']);
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createAccount(string $email = 'member@example.test', bool $banned = false): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Recovery member',
            'email' => $email,
            'password' => 'OriginalPassword2026!',
            'is_banned' => $banned,
            'role' => 'member',
            'color' => 'violet',
        ])->save();
        return $user;
    }

    private function issueToken(User $user, string $language = 'pl'): string
    {
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email], ['X-WojteX-Locale' => $language])
            ->assertOk()->assertJsonStructure(['message']);
        $sent = Notification::sent($user, VeyraPasswordReset::class);
        $this->assertCount(1, $sent);
        $token = $sent->first()->token;
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{64}$/D', $token);
        return $token;
    }

    private function resetPayload(User $user, string $token, string $password = 'SecureNewPassword2026!'): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => $password, 'password_confirmation' => $password];
    }

    public function test_known_unknown_and_banned_addresses_receive_the_same_public_answer(): void
    {
        $known = $this->createAccount();
        $banned = $this->createAccount('banned@example.test', true);
        $expected = $this->postJson('/api/auth/password/forgot', ['email' => 'nobody@example.test'], ['X-WojteX-Locale' => 'en'])
            ->assertOk()->json('message');
        foreach ([$known->email, $banned->email] as $email) {
            $this->postJson('/api/auth/password/forgot', ['email' => $email], ['X-WojteX-Locale' => 'en'])
                ->assertOk()->assertJsonPath('message', $expected);
        }
        Notification::assertNotSentTo($banned, VeyraPasswordReset::class);
        Notification::assertSentTo($known, VeyraPasswordReset::class);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $banned->email]);
    }

    public function test_link_is_hashed_at_rest_and_anchored_to_configured_origin_not_host_header(): void
    {
        $user = $this->createAccount();
        $this->withHeader('Host', 'attacker.example');
        $token = $this->issueToken($user, 'en');
        $stored = DB::table('password_reset_tokens')->where('email', $user->email)->first();
        $this->assertNotNull($stored);
        $this->assertNotSame($token, $stored->token);
        $this->assertTrue(Hash::check($token, $stored->token));
        $notice = Notification::sent($user, VeyraPasswordReset::class)->first();
        $this->assertSame('en', $notice->locale);
        $mail = $notice->toMail($user);
        $this->assertSame('Reset your WojteX Forum password', $mail->subject);
        $this->assertStringStartsWith('http://localhost:8080/#/reset-password?token=', $mail->actionUrl);
        $this->assertStringContainsString('&email=member%40example.test', $mail->actionUrl);
        $this->assertStringNotContainsString('attacker.example', $mail->actionUrl);
    }

    public function test_a_reset_changes_password_once_and_clears_imported_legacy_credentials(): void
    {
        $user = $this->createAccount();
        $user->forceFill([
            'legacy_password_hash' => str_repeat('a', 32),
            'legacy_password_salt' => 'old-salt',
            'remember_token' => 'known-remember-token',
        ])->save();
        $token = $this->issueToken($user);
        $data = $this->resetPayload($user, $token);
        $this->postJson('/api/auth/password/reset', $data)->assertOk()->assertJsonStructure(['message']);
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check($data['password'], $fresh->password));
        $this->assertFalse(Hash::check('OriginalPassword2026!', $fresh->password));
        $this->assertNull($fresh->legacy_password_hash);
        $this->assertNull($fresh->legacy_password_salt);
        $this->assertNotSame('known-remember-token', $fresh->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->postJson('/api/auth/password/reset', $data)->assertUnprocessable();
    }

    public function test_a_password_reset_revokes_an_existing_login_session_on_its_next_request(): void
    {
        $user = $this->createAccount();
        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'OriginalPassword2026!',
        ])->assertOk();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user.id', $user->id);

        $token = $this->issueToken($user);
        $this->postJson('/api/auth/password/reset', $this->resetPayload($user, $token))
            ->assertOk();

        // Recreate the guard as an ordinary subsequent HTTP request would.
        // It must re-read the changed hash from the account in the database.
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/bootstrap')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/bootstrap')->assertOk()->assertJsonPath('user', null);
    }

    public function test_expired_token_cannot_be_used_and_is_rejected_with_same_message_as_invalid_token(): void
    {
        $user = $this->createAccount();
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00'));
        $token = $this->issueToken($user);
        Carbon::setTestNow(Carbon::parse('2026-10-22 13:01:00'));
        $expired = $this->postJson('/api/auth/password/reset', $this->resetPayload($user, $token))
            ->assertUnprocessable()->json('message');
        $this->assertTrue(Hash::check('OriginalPassword2026!', $user->fresh()->password));
        $bad = $this->postJson('/api/auth/password/reset', $this->resetPayload($user, str_repeat('Z', 64)))
            ->assertUnprocessable()->json('message');
        $this->assertSame($expired, $bad);
    }

    public function test_wrong_token_does_not_consume_valid_token(): void
    {
        $user = $this->createAccount();
        $token = $this->issueToken($user);
        $wrong = str_repeat('Z', 64);
        $this->assertNotSame($token, $wrong);
        $this->postJson('/api/auth/password/reset', $this->resetPayload($user, $wrong))->assertUnprocessable();
        $this->postJson('/api/auth/password/reset', $this->resetPayload($user, $token))->assertOk();
    }

    public function test_account_throttle_does_not_expose_user_or_send_multiple_links(): void
    {
        $user = $this->createAccount();
        $first = $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk()->json('message');
        $second = $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertOk()->json('message');
        $this->assertSame($first, $second);
        $this->assertCount(1, Notification::sent($user, VeyraPasswordReset::class));
        $this->assertDatabaseCount('password_reset_tokens', 1);
    }

    public function test_ip_limit_applies_to_forgot_requests(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/password/forgot', ['email' => 'absent'.$attempt.'@example.test'])->assertOk();
        }
        $this->postJson('/api/auth/password/forgot', ['email' => 'sixth@example.test'])->assertStatus(429);
    }

    public function test_reset_banned_account_is_blocked_even_with_previous_valid_token(): void
    {
        $user = $this->createAccount();
        $token = $this->issueToken($user);
        $user->forceFill(['is_banned' => true])->save();
        $this->postJson('/api/auth/password/reset', $this->resetPayload($user, $token))->assertUnprocessable();
        $this->assertTrue(Hash::check('OriginalPassword2026!', $user->fresh()->password));
    }

    /**
     * A test-only broker invokes the real controller callback after a ban.
     * Other tests in this class cover the real token repository and delivery.
     * This case is not a test of physical concurrent requests.
     */
    public function test_ban_between_broker_lookup_and_reset_callback_blocks_password_change(): void
    {
        $user = $this->createAccount();
        $oldHash = $user->password;
        $broker = \Mockery::mock();
        $broker->shouldReceive('reset')->once()->andReturnUsing(
            function (array $credentials, callable $callback) use ($user): string {
                DB::table('users')->where('id', $user->id)->update(['is_banned' => true]);
                $callback($user, $credentials['password']);
                return Password::PASSWORD_RESET;
            }
        );
        Password::shouldReceive('broker')->once()->andReturn($broker);

        $this->postJson('/api/auth/password/reset', $this->resetPayload($user, str_repeat('T', 64)))
            ->assertUnprocessable()->assertJsonPath('message', __('server.password_reset_invalid'));
        $fresh = $user->fresh();
        $this->assertTrue($fresh->is_banned);
        $this->assertSame($oldHash, $fresh->password);
        $this->assertTrue(Hash::check('OriginalPassword2026!', $fresh->password));
    }

    public function test_password_confirmation_strength_and_token_shape_are_validated(): void
    {
        $user = $this->createAccount();
        $token = $this->issueToken($user);
        $this->postJson('/api/auth/password/reset', $this->resetPayload($user, 'invalid'))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $weak = $this->resetPayload($user, $token, 'short');
        $this->postJson('/api/auth/password/reset', $weak)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $weak = $this->resetPayload($user, $token);
        $weak['password_confirmation'] = 'DifferentPassword2026!';
        $this->postJson('/api/auth/password/reset', $weak)
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/auth/password/reset', $this->resetPayload($user, $token))->assertOk();
    }

    public function test_recovery_is_disabled_with_log_mailer_or_insecure_nonlocal_url(): void
    {
        $user = $this->createAccount();
        config(['mail.default' => 'log']);
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertStatus(503);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        config(['mail.default' => 'array', 'app.url' => 'http://insecure.example.test']);
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertStatus(503);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        Notification::assertNothingSent();
    }

    public function test_public_recovery_mutations_still_require_session_csrf(): void
    {
        $this->app->bind(PreventRequestForgery::class, EnforcedCsrfForPasswordRecovery::class);
        $user = $this->createAccount();
        $this->withSession(['_token' => 'password-test-token']);
        $this->postJson('/api/auth/password/forgot', ['email' => $user->email])->assertStatus(419);
        Notification::assertNothingSent();
        $this->withHeader('X-CSRF-TOKEN', 'password-test-token');
        $token = $this->issueToken($user);
        $this->withHeader('X-CSRF-TOKEN', 'incorrect-token');
        $this->postJson('/api/auth/password/reset', $this->resetPayload($user, $token))->assertStatus(419);
        $this->withHeader('X-CSRF-TOKEN', 'password-test-token');
        $this->postJson('/api/auth/password/reset', $this->resetPayload($user, $token))->assertOk();
    }

    public function test_polish_and_english_reset_emails_and_responses_use_correct_catalog(): void
    {
        $english = $this->createAccount('english@example.test');
        $polish = $this->createAccount('polski@example.test');
        $en = $this->postJson('/api/auth/password/forgot', ['email' => $english->email], ['X-WojteX-Locale' => 'en'])->assertOk()->json('message');
        $pl = $this->postJson('/api/auth/password/forgot', ['email' => $polish->email], ['X-WojteX-Locale' => 'pl'])->assertOk()->json('message');
        $this->assertNotSame($en, $pl);
        $enNotice = Notification::sent($english, VeyraPasswordReset::class)->first();
        $plNotice = Notification::sent($polish, VeyraPasswordReset::class)->first();
        $this->assertSame('en', $enNotice->locale);
        $this->assertSame('pl', $plNotice->locale);
        $this->assertNotSame($enNotice->toMail($english)->subject, $plNotice->toMail($polish)->subject);
        $mail = $plNotice->toMail($polish);
        $this->assertStringContainsString('60', implode(' ', [...$mail->introLines, ...$mail->outroLines]));
    }
}

class EnforcedCsrfForPasswordRecovery extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return false;
    }
}
