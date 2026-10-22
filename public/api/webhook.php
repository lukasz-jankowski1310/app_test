<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/lib/webhooks.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

try {
    $configuration = webhook_configuration();
    $body = file_get_contents('php://input', false, null, 0, 4194305);
    if ($body === false) {
        throw new RuntimeException('Could not read webhook.');
    }

    if (strlen($body) > 4194304) {
        http_response_code(413);
        exit;
    }

    if (($_SERVER['HTTP_X_WEBHOOK_ID'] ?? '') !== $configuration['webhookId'] ||
        !webhook_signature_valid($body, $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '', $configuration['secrets'], time())) {
        http_response_code(403);
        echo '{"error":"Invalid webhook signature"}';
        exit;
    }

    $event = webhook_event($body, $_SERVER);
    $stored = store_webhook(webhook_database(), $event, $body);
    // Acknowledge only after the SQLite commit; non-2xx makes Zapq retry the delivery.
    echo json_encode(['accepted' => true, 'duplicate' => !$stored], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException | JsonException $exception) {
    http_response_code(400);
    echo '{"error":"Invalid webhook payload"}';
} catch (Throwable $exception) {
    http_response_code(503);
    echo '{"error":"Webhook receiver unavailable"}';
}
