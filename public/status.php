<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/zapq.php';
require dirname(__DIR__) . '/lib/webhooks.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

try {
    if (!is_string($_GET['runId'] ?? null)) {
        throw new InvalidArgumentException('Provide a runId.');
    }

    // Read the received webhook locally. No request to the Zapq run-status API.
    $context = zapq_context();
    $result = application_run_result(webhook_database(), $_GET['runId'], $context['user_id']);
    if ($result === null) {
        http_response_code(404);
        echo '{"error":"Run not found for this user in this application"}';
        exit;
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Could not read the received webhook'], JSON_THROW_ON_ERROR);
}
