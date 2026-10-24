<?php
namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ForumService as Forum;
use App\Services\ProfileUpdateQuery;
use App\Services\RegistrationInsertQuery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        abort_unless(Forum::settings()['registration_open'],403,__('server.registration_closed'));
        if(is_string($request->input('email'))) $request->merge(['email'=>mb_strtolower(trim($request->input('email')))]);
        $data=$request->validate(['name'=>'required|string|min:2|max:50','email'=>'required|email:rfc|max:254|unique:users,email','password'=>['required','string','max:128','confirmed',Password::min(12)]]);
        // Password hashing/validation may be expensive. In that time an
        // administrator can CLOSE registrations after the earlier settings()
        // check. Evaluate the canonical persisted switch in the actual INSERT.
        // Keep the User hashed cast, default role and explicit registration event.
        $user=new User(); $user->name=$data['name']; $user->email=$data['email']; $user->password=$data['password'];
        $user->role='member'; $user->color=Forum::COLORS[random_int(0,5)];
        $time=now()->toDateTimeString();
        $inserted=DB::table('users')->insertUsing(
            ['name','email','password','role','color','created_at','updated_at'],
            RegistrationInsertQuery::eligible($user->name,$user->email,$user->password,
                $user->role,$user->color,$time)
        );
        abort_unless($inserted===1,403,__('server.registration_closed'));
        $user=User::findOrFail((int)DB::connection()->getPdo()->lastInsertId());
        Auth::login($user); $request->session()->regenerate();
        Event::dispatch('veyra.user.registered',[$user->id]);
        return response()->json(['user'=>Forum::selfUser($user)],201);
    }

    public function login(Request $request)
    {
        $data=$request->validate(['email'=>'required|string|max:254','password'=>'required|string|max:128']);
        $user=User::where('email',mb_strtolower(trim($data['email'])))->first();
        $valid=false; $legacy=false;
        if ($user && !$user->is_banned) {
            if ($user->legacy_password_hash && $user->legacy_password_salt !== null) {
                $valid=hash_equals(strtolower($user->legacy_password_hash),md5(md5($user->legacy_password_salt).md5($data['password'])));
                $legacy=$valid;
            } else {
                try { $valid=Hash::check($data['password'],$user->password); } catch (\RuntimeException) { $valid=false; }
            }
        }
        if (!$valid) {
            // Equalize absent/banned/legacy account work without disclosing account state.
            if (!$user || $user->is_banned || $user->legacy_password_hash) Hash::make($data['password']);
            throw ValidationException::withMessages(['email'=>__('server.invalid_credentials')]);
        }
        // Hash verification can be slow. A password reset, admin ban, account
        // deletion or legacy credential migration may commit AFTER the first
        // lookup/check but BEFORE the session is issued. Re-read and lock the
        // authoritative account, rejecting any changed login material. Keep
        // the lock through Auth::login and any legacy-password upgrade.
        $current = DB::transaction(function () use ($user, $data, $legacy) {
            $fresh = User::whereKey($user->getKey())->lockForUpdate()->first();
            if (!$fresh || $fresh->is_banned
                || $fresh->email !== $user->email
                || $fresh->password !== $user->password
                || $fresh->legacy_password_hash !== $user->legacy_password_hash
                || $fresh->legacy_password_salt !== $user->legacy_password_salt) {
                throw ValidationException::withMessages(['email'=>__('server.invalid_credentials')]);
            }
            if ($legacy || Hash::needsRehash($fresh->password)) {
                $fresh->password=$data['password'];
                $fresh->legacy_password_hash=null;
                $fresh->legacy_password_salt=null;
                $fresh->save();
            }
            Auth::login($fresh);
            return $fresh;
        });
        $request->session()->regenerate();
        return response()->json(['user'=>Forum::selfUser($current)]);
    }

    public function logout(Request $request)
    {
        Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken();
        return response()->json(['ok'=>true]);
    }

    public function profile(Request $request)
    {
        // An already-authenticated account can be banned after its session
        // starts. Profile changes (including public display name/bio) must
        // not remain an alternate write path after moderation revokes access.
        // Keep logout available so the user can end the session normally.
        abort_if((bool) $request->user()->is_banned, 403, __('server.account_blocked'));
        $data=$request->validate([
            'name' => 'required|string|min:2|max:50',
            'bio' => 'nullable|string|max:500',
            'allow_private_messages' => 'sometimes|required|boolean',
        ]);
        $user = $request->user();
        $changes = ['name' => $data['name'], 'bio' => $data['bio'] ?? ''];
        // Old clients that omit this preference must not silently reset it.
        if (array_key_exists('allow_private_messages', $data)) {
            $changes['allow_private_messages'] = (bool) $data['allow_private_messages'];
        }
        // A ban or account removal may commit AFTER the User object was read
        // but BEFORE the save. Never issue an unconditional Eloquent save here:
        // require the live user's unbanned row in the SAME SQL UPDATE.
        $affected = ProfileUpdateQuery::eligible((int) $user->id)
            ->update($changes + ['updated_at' => now()]);
        if ($affected !== 1) {
            // MySQL can report zero affected rows for a legitimate no-op.
            // A post-ban failed UPDATE is only successful if the still-active
            // row matches every explicitly requested field. The check has no
            // writes or user-visible details on rejected accounts.
            $current = $affected === 0
                ? ProfileUpdateQuery::eligible((int) $user->id)
                    ->first(['name', 'bio', 'allow_private_messages'])
                : null;
            abort_unless($current && $current->name === $changes['name']
                && $current->bio === $changes['bio']
                && (!array_key_exists('allow_private_messages', $changes)
                    || (bool) $current->allow_private_messages === $changes['allow_private_messages']),
                403, __('server.account_blocked'));
        }
        // Serialize from the authoritative row, not the cached authenticated
        // User instance, which may still contain old PM preferences.
        $user->refresh();
        return response()->json(['user'=>Forum::selfUser($user)]);
    }
}
