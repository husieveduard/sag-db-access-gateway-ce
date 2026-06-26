<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED = ['uk', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $defaultLocale = (string) config('app.locale', 'uk');

        $locale = $request->hasSession()
            ? (string) $request->session()->get('locale', $defaultLocale)
            : $defaultLocale;

        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = $defaultLocale;
        }

        App::setLocale($locale);

        return $next($request);
    }
}
