<?php

namespace Sellissimo\SzamlazzHu\Http;

use Closure;
use DomainException;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class PrivatePage
{
    public function handle(Request $request, Closure $next)
    {
        // The settings hub uses Inertia links. Switch into the server-rendered operator form.
        if ($request->isMethod('get') && $request->header('X-Inertia')) {
            return Inertia::location($request->fullUrl());
        }
        $response = $next($request);
        $error = $response->exception ?? null;
        if ($error instanceof DomainException) {
            if ($request->expectsJson()) {
                $response = response()->json(['message' => $error->getMessage()], 422);
            } else {
                $redirect = $request->route('order') ? redirect(cp_route('szamlazz.order', $request->route('order'))) : back();
                $response = $redirect->withErrors(['invoice' => $error->getMessage()]);
            }
        }
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
