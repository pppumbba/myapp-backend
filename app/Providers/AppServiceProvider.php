<?php

namespace App\Providers;

use App\Support\Market\Dan\DanDriver;
use App\Support\Market\Dan\FakeDanDriver;
use App\Support\Market\Khur\FakeKhurDriver;
use App\Support\Market\Khur\KhurDriver;
use App\Support\Market\Pay\FakePaymentDriver;
use App\Support\Market\Pay\PaymentDriver;
use App\Support\Market\Pay\QPayDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The market's DAN connection: only the fake one exists until the government agreement does.
        $this->app->bind(DanDriver::class, function () {
            if (config('market.dan.driver') !== 'fake') {
                throw new RuntimeException('Only the fake DAN driver exists yet.');
            }

            return new FakeDanDriver;
        });
        // The market's ХУР connection, likewise fake for now.
        $this->app->bind(KhurDriver::class, function () {
            if (config('market.khur.driver') !== 'fake') {
                throw new RuntimeException('Only the fake ХУР driver exists yet.');
            }

            return new FakeKhurDriver;
        });
        // Where market fees and escrow are paid: the fake test page, or QPay.
        $this->app->bind(PaymentDriver::class, fn () => match (config('market.payments.driver')) {
            'fake' => new FakePaymentDriver,
            'qpay' => new QPayDriver,
            default => throw new RuntimeException('Unknown market payment driver.'),
        });
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
