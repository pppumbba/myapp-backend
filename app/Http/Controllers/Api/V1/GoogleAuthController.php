<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\SessionValues;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign in with Google (OAuth 2.0 / OpenID Connect, config services.google).
 *
 * The browser goes to redirect(), which sends it to Google with a state kept in the session.
 * Google sends it back to callback() with a code, which is swapped for the person's Google id,
 * email and name. They are matched to a user: by the linked Google account, else by a
 * verified email (and linked), else a new account when sign-ups are on. The browser then goes
 * to the frontend's /login with a one-time code, which exchange() turns into the usual token,
 * so the token never shows up in a URL.
 */
class GoogleAuthController extends Controller
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    /** GET /api/auth/providers — the sign-in providers that are set up. */
    public function providers(): JsonResponse
    {
        return response()->json(['google' => self::enabled()]);
    }

    /** GET /auth/google/redirect — off to Google's sign-in page. */
    public function redirect(Request $request): RedirectResponse
    {
        if (! self::enabled()) {
            return $this->toFrontend(['google_error' => __('Signing in with Google is not set up.')]);
        }
        $state = Str::random(40);
        $request->session()->put('google_state', $state);
        $config = config('services.google');

        return redirect()->away(self::AUTH_URL.'?'.http_build_query(array_filter([
            'client_id' => $config['client_id'],
            'redirect_uri' => $config['redirect'],
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'prompt' => 'select_account',
            // Google shows only that domain's accounts; the callback still checks it.
            'hd' => $config['allowed_domain'] ?: null,
        ])));
    }

    /** GET /auth/google/callback — back from Google: find or make the user, hand the frontend a one-time code. */
    public function callback(Request $request): RedirectResponse
    {
        $state = $request->session()->pull('google_state');
        if (! self::enabled() || ! is_string($state) || ! hash_equals($state, (string) $request->query('state'))) {
            return $this->toFrontend(['google_error' => __('The Google sign-in expired. Try again.')]);
        }
        if ($request->filled('error') || ! $request->filled('code')) {
            // The person pressed Cancel at Google, or Google refused.
            return $this->toFrontend(['google_error' => __('Google sign-in was cancelled.')]);
        }

        try {
            $profile = $this->profile((string) $request->query('code'));
        } catch (Throwable $e) {
            report($e);

            return $this->toFrontend(['google_error' => __('Google could not be reached. Try again.')]);
        }

        $user = $this->userFor($profile);
        if (is_string($user)) {
            return $this->toFrontend(['google_error' => $user]);
        }

        $code = Str::random(64);
        Cache::put("google-login:{$code}", $user->id, now()->addMinute());

        return $this->toFrontend(['google_code' => $code]);
    }

    /** POST /api/auth/google/exchange {code} — the one-time code for the user and a token. */
    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:100']]);
        $user = User::find(Cache::pull("google-login:{$data['code']}"));
        if (! $user) {
            return response()->json(['message' => __('The Google sign-in expired. Try again.')], 401);
        }

        SessionValues::refresh($user);

        return response()->json([
            'user' => $user,
            'token' => $user->createToken('api')->plainTextToken,
        ]);
    }

    /** Whether the client id and secret are set. */
    public static function enabled(): bool
    {
        return filled(config('services.google.client_id')) && filled(config('services.google.client_secret'));
    }

    /**
     * The person Google signed in: swaps the code for an access token, then reads their profile.
     *
     * @return array{sub: string, email: ?string, email_verified: bool, name: ?string, hd: ?string}
     */
    private function profile(string $code): array
    {
        $config = config('services.google');
        $token = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $config['client_id'],
            'client_secret' => $config['client_secret'],
            'redirect_uri' => $config['redirect'],
            'grant_type' => 'authorization_code',
        ])->throw()->json('access_token');

        $info = Http::withToken((string) $token)->timeout(15)->get(self::USERINFO_URL)->throw()->json();
        if (! is_array($info) || ! filled($info['sub'] ?? null)) {
            throw new \RuntimeException('Google answered without the person\'s id.');
        }

        return [
            'sub' => (string) $info['sub'],
            'email' => isset($info['email']) ? mb_strtolower((string) $info['email']) : null,
            'email_verified' => ($info['email_verified'] ?? false) === true || ($info['email_verified'] ?? null) === 'true',
            'name' => filled($info['name'] ?? null) ? (string) $info['name'] : null,
            'hd' => $info['hd'] ?? null,
        ];
    }

    /**
     * The user for a Google profile, linking or making one as needed; or why they may not sign in.
     *
     * @param  array{sub: string, email: ?string, email_verified: bool, name: ?string, hd: ?string}  $profile
     */
    private function userFor(array $profile): User|string
    {
        $domain = config('services.google.allowed_domain');
        if ($domain && strcasecmp((string) $profile['hd'], $domain) !== 0) {
            return __('Only :domain accounts may sign in with Google.', ['domain' => $domain]);
        }

        $account = SocialAccount::where('provider', 'google')->where('provider_id', $profile['sub'])->first();
        if ($account && $account->user) {
            $account->update(['email' => $profile['email']]);

            return $account->user;
        }

        // Matching by email trusts it, so Google must have checked it belongs to them.
        if (! $profile['email'] || ! $profile['email_verified']) {
            return __('Google has not confirmed this account\'s email address.');
        }

        $user = User::where('email', $profile['email'])->first();
        if (! $user) {
            if (! config('services.google.signup')) {
                return __('There is no account for :email. Ask an administrator to add you.', ['email' => $profile['email']]);
            }
            $user = User::startAccount([
                'name' => $profile['name'] ?? Str::before($profile['email'], '@'),
                'email' => $profile['email'],
                // They sign in with Google; a password nobody knows until they set one.
                'password' => Str::random(40),
            ]);
        }
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
        $user->socialAccounts()->updateOrCreate(['provider' => 'google', 'provider_id' => $profile['sub']], ['email' => $profile['email']]);

        return $user;
    }

    /** Back to the frontend's login page with a code or an error. */
    private function toFrontend(array $query): RedirectResponse
    {
        return redirect()->away(rtrim((string) config('app.frontend_url'), '/').'/login?'.http_build_query($query));
    }
}
