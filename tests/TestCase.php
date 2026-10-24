<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable as UserContract;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * actingAs() is a simulated login: keep its session fingerprint in sync.
     * Unlike a real SessionGuard::login(), Laravel's testing be() only changes
     * the in-memory guard user. Multi-account tests intentionally switch user
     * contexts without a /logout -> /login HTTP round trip.
     */
    public function be(UserContract $user, $guard = null)
    {
        parent::be($user, $guard);

        $guardName = $guard ?? $this->app['auth']->getDefaultDriver();
        $driver = $this->app['auth']->guard($guardName);
        if ($user->getAuthPassword() && method_exists($driver, 'hashPasswordForCookie')) {
            $this->withSession([
                'password_hash_'.$guardName => $driver->hashPasswordForCookie($user->getAuthPassword()),
            ]);
        }

        return $this;
    }
}
