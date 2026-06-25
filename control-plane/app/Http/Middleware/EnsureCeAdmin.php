<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureCeAdmin
{
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        abort_unless(
            $user
                && $user->is_active
                && $user->role === 'admin',
            403,
            'Administrator access is required.'
        );

        return $next($request);
    }
}
