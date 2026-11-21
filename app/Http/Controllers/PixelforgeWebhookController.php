<?php

namespace App\Http\Controllers;

use App\Services\Generations\GenerationStateService;
use App\Services\Pixelforge\WebhookSignatureVerifier;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        WebhookSignatureVerifier $verifier,
        GenerationStateService $stateService,
    ): Response {
        $rawPayload = $request->getContent();
        if (! $verifier->verify($rawPayload, $request->header('Pixelforge-Signature'))) {
            abort(400, 'Invalid Pixelforge signature.');
        }
        $event = json_decode($rawPayload, true);
        if (! is_array($event) || empty($event['id']) || empty($event['type'])) {
            abort(400, 'Malformed Pixelforge webhook payload.');
        }
        $eventId = (string) $event['id'];
        $eventType = (string) $event['type'];
        $object = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : null;
        $providerGenerationId = is_array($object) && isset($object['id']) ? (string) $object['id'] : null;
        $deliveryAttempt = max(1, (int) $request->header('Pixelforge-Delivery-Attempt', '1'));
        DB::transaction(function () use (
            $eventId,
            $eventType,
            $providerGenerationId,
            $deliveryAttempt,
            $rawPayload,
            $object,
            $stateService,
        ): void {
            $inserted = DB::table('pixelforge_webhook_events')->insertOrIgnore([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'provider_generation_id' => $providerGenerationId,
                'delivery_attempt' => $deliveryAttempt,
                'payload' => $rawPayload,
                'received_at' => now('UTC'),
            ]);
            // Duplicate delivery (already processed) — acknowledge cleanly without re-running side effects.
            if ($inserted === 0) {
                return;
            }
            if (in_array($eventType, ['generation.processing', 'generation.succeeded', 'generation.failed'], true) && $object !== null) {
                $stateService->applyProviderPayload($object);
            }
        });
        return response()->noContent();
    }
}
