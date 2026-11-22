<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/** Optional IP allowlist + shared access key (X-Access-Key header) for /api routes. */
class GoldScannerGuard
{
    public function handle(Request $request, Closure $next, string $mode = 'key'): Response
    {
        $nets = config('goldscanner.allowed_networks', []);
        if ($nets && ! IpUtils::checkIp((string) $request->ip(), $nets)) {
            return response()->json(['detail' => 'forbidden'], 403);
        }
        if ($mode === 'key') {
            $key = (string) config('goldscanner.access_key');
            if ($key !== '' && ! hash_equals($key, (string) $request->header('X-Access-Key', ''))) {
                return response()->json(['detail' => 'مفتاح الدخول غير صحيح'], 401);
            }
        }
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
