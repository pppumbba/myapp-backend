<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes small "something changed" notes to browsers through Centrifugo (config services.centrifugo),
 * so they fetch the change instead of polling for it. Each user has one channel per customer, given
 * in the connection token this signs, so a browser hears only about the customer it has open.
 * Off when Centrifugo isn't configured (local, tests): browsers then poll as before.
 */
class Realtime
{
    /** How long a connection token lasts; the browser asks for a new one before it runs out. */
    public const TOKEN_MINUTES = 60;

    public static function enabled(): bool
    {
        return filled(config('services.centrifugo.url')) && filled(config('services.centrifugo.api_key')) && filled(config('services.centrifugo.secret'));
    }

    /** The user's channel in the customer whose schema is in use. */
    public static function channel(int $userId): string
    {
        return 'u'.(CustomerSchema::current()?->id ?? 0).'_'.$userId;
    }

    /**
     * Sends $data to these users' channels. A failure is logged and otherwise ignored: the browsers
     * still catch up on their next fallback poll.
     *
     * @param  array<int, int>  $userIds
     * @param  array<string, mixed>  $data
     */
    public static function toUsers(array $userIds, array $data): void
    {
        if (! self::enabled() || $userIds === []) {
            return;
        }
        try {
            Http::timeout(2)
                ->withHeaders(['X-API-Key' => (string) config('services.centrifugo.api_key')])
                ->post(rtrim((string) config('services.centrifugo.url'), '/').'/api/broadcast', [
                    'channels' => array_values(array_map(fn (int $id) => self::channel($id), array_unique($userIds))),
                    'data' => $data,
                ])
                ->throw();
        } catch (Throwable $e) {
            Log::warning('Realtime broadcast failed: '.$e->getMessage());
        }
    }

    /**
     * What a browser connects with: the WebSocket address and a token (HS256 JWT) that subscribes
     * it to the user's channel on the server's side. Null when Centrifugo isn't configured.
     *
     * @return array{url: string, token: string, expires_at: int}|null
     */
    public static function connection(User $user): ?array
    {
        if (! self::enabled()) {
            return null;
        }
        $expires = now()->addMinutes(self::TOKEN_MINUTES)->getTimestamp();

        return [
            'url' => (string) (config('services.centrifugo.ws_url') ?: preg_replace('#^http#', 'ws', rtrim((string) config('app.url'), '/')).'/connection/websocket'),
            'token' => self::jwt(['sub' => (string) $user->id, 'exp' => $expires, 'channels' => [self::channel($user->id)]]),
            'expires_at' => $expires,
        ];
    }

    /** @param  array<string, mixed>  $claims */
    private static function jwt(array $claims): string
    {
        $encode = fn (string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $head = $encode((string) json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = $encode((string) json_encode($claims));

        return "{$head}.{$body}.".$encode(hash_hmac('sha256', "{$head}.{$body}", (string) config('services.centrifugo.secret'), true));
    }
}
