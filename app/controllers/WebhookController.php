<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\GatewayPaymentService;

/** Inbound provider webhooks. Authenticated by signature, not session or CSRF. */
final class WebhookController extends Controller
{
    public function stripe(): void
    {
        $payload = (string) file_get_contents('php://input', false, null, 0, 512 * 1024);
        [$status, $message] = GatewayPaymentService::handleStripeWebhook($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''));
        json_response(['received' => $status === 200, 'result' => $message], $status);
    }
}
