<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the internal routes used by the local WhatsApp Web worker: the request must
 * come from this computer and carry the shared secret (WHATSAPP_WORKER_TOKEN).
 */
class VerifyWorkerToken
{
    private const LOCAL_ADDRESSES = ['127.0.0.1', '::1'];

    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('educationhub.whatsapp.worker.token');

        abort_unless(in_array($request->server('REMOTE_ADDR'), self::LOCAL_ADDRESSES, true), 403);
        abort_if($token === '' || ! hash_equals($token, (string) $request->header('X-Worker-Token')), 401);

        return $next($request);
    }
}
