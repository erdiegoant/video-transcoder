<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('services.transcoder.webhook_secret');
        $signature = $request->header('X-Signature');

        if (! $signature || ! $secret) {
            abort(403, 'Missing webhook signature.');
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(403, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
