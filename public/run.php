<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/zapq.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}

try {
    if (!is_string($_POST['method'] ?? null)) {
        throw new InvalidArgumentException('Provide an API method name.');
    }

    // The gateway has already checked the session, Open and CSRF. The API checks Pipelines.Run.
    $result = zapq_run_pipeline($_POST['method'], ['source' => 'web-app']);
    http_response_code($result['status']);
    echo $result['body'];
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Could not call Zapq API'], JSON_THROW_ON_ERROR);
}
