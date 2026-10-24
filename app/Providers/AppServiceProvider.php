<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\RateLimiter::for('login',fn(\Illuminate\Http\Request $r)=>[
            \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by('ip:'.$r->ip()),
            \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('account:'.hash('sha256',is_string($r->input('email'))?mb_strtolower($r->input('email')):'invalid-input'))
        ]);
        \Illuminate\Support\Facades\RateLimiter::for('register',fn(\Illuminate\Http\Request $r)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('posting',fn(\Illuminate\Http\Request $r)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by($r->user()?->id??$r->ip()));
        \Illuminate\Support\Facades\RateLimiter::for('shout',fn(\Illuminate\Http\Request $r)=>\Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by($r->user()?->id??$r->ip()));
        \App\Services\ExtensionRegistry::boot();
    }
}
