<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\PasswordResetWriteQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password as PasswordRule;

/** Public recovery endpoints. Neither account existence nor throttling leaks via responses. */
class PasswordRecoveryController extends Controller
{
    private const DELIVERY_TRANSPORTS = ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'];

    private function deliveryConfigured(): bool
    {
        $mailer = config('mail.default');
        if (!in_array($mailer, self::DELIVERY_TRANSPORTS, true)
            && !(app()->environment('testing') && $mailer === 'array')) return false;

        // Never produce a recovery link to an attacker-controlled request Host header.
        // In production, link delivery requires HTTPS and a configured APP_URL.
        $url = (string) config('app.url');
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return false;
        $local = in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1'], true);
        return $parts['scheme'] === 'https' || ($local && $parts['scheme'] === 'http');
    }

    public function forgot(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate(['email' => 'required|email:rfc|max:254']);

        // The stock 'log' and 'array' mailers must not write real reset tokens
        // to potentially readable application logs or silently drop them.
        if (!$this->deliveryConfigured()) {
            return response()->json(['message' => __('server.password_mail_unavailable')], 503);
        }

        $response = ['message' => __('server.password_reset_sent')];
        // Both unknown/banned and eligible accounts spend at least the same
        // baseline time. This does not promise perfect network-side timing
        // equality, especially with slow remote mail providers.
        (new Timebox())->call(static function () use ($data): void {
            $user = User::where('email', $data['email'])->first();
            if (!$user || $user->is_banned) return;

            try {
                // Laravel's token repository hashes tokens at rest, expires them
                // after 60 minutes and throttles requests per address for 60 seconds.
                Password::broker()->sendResetLink(['email' => $data['email']]);
            } catch (\Throwable $exception) {
                // Mail failure must not expose whether the address is registered.
                // Never include an address, token, URL or mail exception body here.
                Log::warning('WojteX password recovery delivery failed', ['exception_type' => get_class($exception)]);
            }
        }, 200000);
        return response()->json($response);
    }

    public function reset(Request $request): JsonResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => mb_strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'email' => 'required|email:rfc|max:254',
            'token' => ['required', 'string', 'regex:/^[A-Za-z0-9]{64}$/D'],
            'password' => ['required', 'string', 'max:128', 'confirmed', PasswordRule::min(12)],
        ]);

        $user = User::where('email', $data['email'])->first();
        if ($user && $user->is_banned) {
            return response()->json(['message' => __('server.password_reset_invalid')], 422);
        }

        $status = Password::broker()->reset($data, static function (User $user, string $password): void {
            // The first ban check and even the broker's user lookup may now
            // be stale. A moderator can ban/delete the account while its
            // otherwise-valid token is being verified. Eloquent save() has
            // no live ban predicate; change the secrets only if the database
            // STILL contains an unbanned account at write time.
            $updated = PasswordResetWriteQuery::eligible((int) $user->getKey())->update([
                'password' => Hash::make($password),
                'legacy_password_hash' => null,
                'legacy_password_salt' => null,
                'remember_token' => Str::random(60),
                'updated_at' => now()->toDateTimeString(),
            ]);
            // Reject instead of telling the broker it successfully rotated
            // credentials. Do not consume the reset token on denied writes.
            abort_unless($updated === 1, 422, __('server.password_reset_invalid'));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __('server.password_reset_invalid')], 422);
        }
        return response()->json(['message' => __('server.password_reset_complete')]);
    }
}
