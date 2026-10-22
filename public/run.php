<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/zapq.php';
require dirname(__DIR__) . '/lib/webhooks.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

try {
    if (!is_string($_POST['method'] ?? null) ||
        (isset($_POST['input']) && !is_string($_POST['input'])) ||
        (isset($_POST['idempotencyKey']) && !is_string($_POST['idempotencyKey']))) {
        throw new InvalidArgumentException('Provide an API method name, optional JSON input and idempotency key.');
    }

    // Check local storage before starting work. The callback may arrive before registration.
    $database = webhook_database();
    $context = zapq_context();
    // The gateway has already checked the session, Open and CSRF. The API checks Pipelines.Run.
    $result = zapq_run_pipeline(
        $_POST['method'],
        $_POST['input'] ?? json_encode(['Name' => 'PHP webhook demo'], JSON_THROW_ON_ERROR),
        ($_POST['idempotencyKey'] ?? '') === '' ? null : $_POST['idempotencyKey']
    );
    if ($result['status'] >= 200 && $result['status'] < 300) {
        $started = json_decode($result['body'], true, 32, JSON_THROW_ON_ERROR);
        register_application_run($database, $started['runId'], $context['user_id'], $_POST['method']);
    }

    http_response_code($result['status']);
    echo $result['body'];
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Could not call Zapq API'], JSON_THROW_ON_ERROR);
}
