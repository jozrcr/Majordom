<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('login', 'login.attempt')) {
            return $next($request);
        }

        $token = config('majordom.token');
        if (empty($token)) {
            abort(503, 'MAJORDOM_TOKEN is not configured.');
        }

        if ($request->session()->get('majordom_authenticated') === true) {
            return $next($request);
        }

        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            $bearerToken = substr($authHeader, 7);
            if (hash_equals((string) $token, (string) $bearerToken)) {
                return $next($request);
            }
        }

        if ($request->expectsJson()) {
            abort(401);
        }

        return redirect()->route('login');
    }
}
