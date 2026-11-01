<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Deposit\WebhookReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReceiveWebhookController
{
    /**
     * Always 202. Rejected or failed calls are recorded in webhook_calls; a
     * provider that sees 4xx/5xx would only retry or disable the endpoint.
     */
    public function __invoke(Request $request, string $gateway, WebhookReceiver $receiver): JsonResponse
    {
        $receiver->receive($gateway, $request->getContent(), $request->header('X-Signature'));

        return response()->json(['received' => true], 202);
    }
}
