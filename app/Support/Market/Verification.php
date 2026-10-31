<?php

namespace App\Support\Market;

use App\Models\User;
use App\Support\Market\Dan\DanDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Signing in with DAN. The signed-in user starts it here (the API has no session), so the state
 * is kept in the cache with their id and what the sign-in is for: verifying the account, or
 * confirming a signature. The browser comes back to the web callback, which finishes it.
 */
class Verification
{
    /**
     * Where to send the user to sign in with DAN.
     *
     * @param  array<string, mixed>|null  $purpose  null to verify the account; e.g. ['type' => 'sign', ...]
     */
    public static function start(User $user, ?array $purpose = null): string
    {
        $state = Str::random(40);
        Cache::put("market-dan:{$state}", ['user' => $user->id, 'purpose' => $purpose], now()->addMinutes(10));

        return app(DanDriver::class)->authorizeUrl($state);
    }

    /**
     * Back from DAN: who started it, what for, and the person DAN says signed in. The state works once.
     *
     * @return array{user: User, purpose: array<string, mixed>|null, citizen: array{registry_number: string, name: string}}
     */
    public static function consume(string $state, string $code): array
    {
        $started = Cache::pull("market-dan:{$state}");
        $user = User::find($started['user'] ?? null);
        abort_unless($user, 422, __('The DAN sign-in expired. Try again.'));

        try {
            $citizen = app(DanDriver::class)->citizen($code);
        } catch (RuntimeException) {
            abort(422, __('The DAN sign-in expired. Try again.'));
        }
        $citizen['registry_number'] = mb_strtoupper(trim($citizen['registry_number']));

        return ['user' => $user, 'purpose' => $started['purpose'] ?? null, 'citizen' => $citizen];
    }

    /** Back from DAN to verify: marks the user who started it as that person. */
    public static function complete(string $state, string $code): User
    {
        ['user' => $user, 'citizen' => $citizen] = self::consume($state, $code);

        return self::verify($user, $citizen);
    }

    /**
     * Marks the user as the person DAN named.
     *
     * @param  array{registry_number: string, name: string}  $citizen
     */
    public static function verify(User $user, array $citizen): User
    {
        $number = $citizen['registry_number'];
        abort_if(User::where('registry_number', $number)->whereKeyNot($user->id)->exists(), 422, __('This person is already verified on another account.'));
        abort_if($user->registry_number !== null && $user->registry_number !== $number, 422, __('This account is already verified as someone else.'));

        if ($user->registry_number === null) {
            $user->forceFill(['registry_number' => $number, 'verified_name' => $citizen['name'], 'verified_at' => now()])->save();
        }

        return $user;
    }
}
