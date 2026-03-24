<?php

namespace App\Http\Controllers;

use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhookService) {}

    public function handle(Request $request): JsonResponse
    {
        $this->webhookService->process($request->json()->all());

        return response()->json(['ok' => true]);
    }
}
