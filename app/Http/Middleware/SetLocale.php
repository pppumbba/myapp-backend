<?php

namespace App\Http\Middleware;

use App\Support\Translations;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of an API request: the signed-in user's choice, else the first language their
 * browser asks for that the app speaks (mn-MN counts as mn), else the default.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::pick($request));

        $response = $next($request);
        $response->headers->set('Content-Language', App::getLocale());

        return $response;
    }

    public static function pick(Request $request): string
    {
        $active = Translations::activeCodes();
        $chosen = $request->user('sanctum')?->locale;
        if ($chosen && in_array($chosen, $active, true)) {
            return $chosen;
        }
        foreach ($request->getLanguages() as $asked) {
            $base = strtolower(strtok(str_replace('_', '-', $asked), '-'));
            if (in_array($base, $active, true)) {
                return $base;
            }
        }

        return Translations::fallback();
    }
}
