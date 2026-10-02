<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery as Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

final class PreventRequestForgery extends Middleware
{
    public function handle($request, Closure $next)
    {
        if (self::shouldBypassForAssayBearer($request)) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    public static function shouldBypassForAssayBearer(Request $request): bool
    {
        if (! EnsureAssayAccess::hasBearerHeader($request)) {
            return false;
        }

        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (in_array($middleware, ['assay.access:usage', 'assay.access:content'], true)) {
                return true;
            }
        }

        return false;
    }
}
