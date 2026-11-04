<?php

use App\Exceptions\ServiceException;
use App\Http\Middleware\AuthenticateOptionally;
use App\Http\Middleware\SavesMetaVersion;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\UseCustomer;
use App\Http\Middleware\UseCustomerFromAddress;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
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
        $middleware->alias(['meta.version' => SavesMetaVersion::class, 'auth.optional' => AuthenticateOptionally::class, 'customer' => UseCustomer::class, 'customer.public' => UseCustomerFromAddress::class]);
        $middleware->api(append: SetLocale::class);
        // The customer is picked before route models ({meta}, {role}…) load, so they come from its schema.
        foreach ([AuthenticateOptionally::class, UseCustomer::class, UseCustomerFromAddress::class] as $first) {
            $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: $first);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // abort() messages are written in English; lang/{locale}.json translates the fixed ones.
        $exceptions->map(HttpException::class, fn (HttpException $e) => $e->getMessage() === '' ? $e
            : new HttpException($e->getStatusCode(), __($e->getMessage()), $e, $e->getHeaders(), $e->getCode()));
        $exceptions->render(function (ServiceException $e) {
            return response()->json(['message' => __($e->getMessage())], 422);
        });
    })->create();
