<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function handle(Request $request): JsonResponse
    {
        Log::info('Webhook LeadLovers recebido.', [
            'payload' => $request->all(),
        ]);

        return response()->json([
            'status' => 'received',
        ]);
    }
}
