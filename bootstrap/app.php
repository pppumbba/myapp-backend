<?php

use App\Exceptions\ServiceException;
use App\Http\Middleware\AuthenticateOptionally;
use App\Http\Middleware\SavesMetaVersion;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo(fn () => null);
        // Behind Render's (or any) proxy: https and the real host come from X-Forwarded-* headers.
        $middleware->trustProxies(at: '*');
        $middleware->alias(['meta.version' => SavesMetaVersion::class, 'auth.optional' => AuthenticateOptionally::class]);
        $middleware->api(append: SetLocale::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // abort() messages are written in English; lang/{locale}.json translates the fixed ones.
        $exceptions->map(HttpException::class, fn (HttpException $e) => $e->getMessage() === '' ? $e
            : new HttpException($e->getStatusCode(), __($e->getMessage()), $e, $e->getHeaders(), $e->getCode()));
        $exceptions->render(function (ServiceException $e) {
            return response()->json(['message' => __($e->getMessage())], 422);
        });
    })->create();
