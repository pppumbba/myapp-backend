<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Support\Market\Dan\DanDriver;
use App\Support\Market\Khur\FakeKhurDriver;
use App\Support\Market\Khur\KhurDriver;
use App\Support\Market\Pay\PaymentDriver;
use App\Support\Market\Pay\QPayDriver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The market's DAN connection: none exists until the government agreement does.
        $this->app->bind(DanDriver::class, function () {
            throw new RuntimeException('No DAN driver is connected yet.');
        });
        // The market's ХУР connection, likewise fake for now.
        $this->app->bind(KhurDriver::class, function () {
            if (config('market.khur.driver') !== 'fake') {
                throw new RuntimeException('Only the fake ХУР driver exists yet.');
            }

            return new FakeKhurDriver;
        });
        // Where market fees and escrow are paid: QPay.
        $this->app->bind(PaymentDriver::class, fn () => match (config('market.payments.driver')) {
            'qpay' => new QPayDriver,
            default => throw new RuntimeException('Unknown market payment driver.'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Tokens live in the main schema and carry the customer they work in.
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Tests run main and customer tables in one database; elsewhere main migrations run on their own
        // (migrate --database=main --path=database/migrations/main).
        if ($this->app->runningUnitTests()) {
            $this->loadMigrationsFrom(database_path('migrations/main'));
        }

        // Guests on public metas (a portal): reads and sends per address; signed-in users are not limited.
        // The token is read here (sanctum guard): this can run before auth.optional picks that guard,
        // and then every signed-in user would share their IP's guest limit.
        RateLimiter::for('public', fn (Request $request) => $request->user('sanctum') ? Limit::none() : [
            Limit::perMinute(120)->by('public:'.$request->ip()),
            ...($request->isMethod('GET') ? [] : [Limit::perMinute(20)->by('public-send:'.$request->ip())]),
        ]);

        // Making a customer makes a schema: a few per person and per address (until pricing limits it).
        RateLimiter::for('customers', fn (Request $request) => [
            Limit::perHour(3)->by('customers:'.$request->user()?->id),
            Limit::perHour(10)->by('customers-ip:'.$request->ip()),
        ]);

        // Guessing invite codes.
        RateLimiter::for('customers-join', fn (Request $request) => Limit::perMinute(10)->by('customers-join:'.$request->user()?->id.'|'.$request->ip()));

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
