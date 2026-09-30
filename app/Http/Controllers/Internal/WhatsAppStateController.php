<?php

namespace App\Http\Controllers\Internal;

use App\Enums\WhatsAppConnectionStatus;
use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * POST /internal/whatsapp/state — the worker reports its connection state
 * (on every change, and every few seconds as a heartbeat).
 *
 * Body: { "state": "CONNECTED" | "WAITING_FOR_QR" | "STARTING" | "DISCONNECTED", "reason": "optional short text" }
 */
class WhatsAppStateController extends Controller
{
    public function __invoke(Request $request, WhatsAppSessionManager $sessions): JsonResponse
    {
        $request->merge(['state' => strtolower((string) $request->input('state'))]);

        $data = $request->validate([
            'state' => ['required', Rule::enum(WhatsAppConnectionStatus::class)],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $session = $sessions->record(WhatsAppConnectionStatus::from($data['state']), 'worker', $data['reason'] ?? null, seen: true);

        return response()->json(['ok' => true, 'state' => $session->status->value]);
    }
}
