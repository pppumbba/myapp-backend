<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        // Guests on public metas (a portal): reads and sends per address; signed-in users are not limited.
        // The token is read here (sanctum guard): this can run before auth.optional picks that guard,
        // and then every signed-in user would share their IP's guest limit.
        RateLimiter::for('public', fn (Request $request) => $request->user('sanctum') ? Limit::none() : [
            Limit::perMinute(120)->by('public:'.$request->ip()),
            ...($request->isMethod('GET') ? [] : [Limit::perMinute(20)->by('public-send:'.$request->ip())]),
        ]);

        // Password guessing: a few tries per account from one address, and a looser cap per address
        // so one machine can't walk through many accounts.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by('login:'.Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        // Sign-up is open, so one address may only make a handful of accounts.
        RateLimiter::for('register', fn (Request $request) => [
            Limit::perMinute(5)->by('register:'.$request->ip()),
            Limit::perHour(20)->by('register-hour:'.$request->ip()),
        ]);

        // Changing the password checks the current one: a stolen token must not be able to guess it.
        RateLimiter::for('password', fn (Request $request) => Limit::perMinute(5)->by('password:'.($request->user()?->id ?? $request->ip())));
    }
}
