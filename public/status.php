<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/zapq.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit;
}

try {
    if (!is_string($_GET['runId'] ?? null) ||
        (isset($_GET['stepRunId']) && !is_string($_GET['stepRunId']))) {
        throw new InvalidArgumentException('Provide a runId and an optional stepRunId.');
    }

    // API checks Logs.View and the pipeline scope for this user on every read.
    $result = zapq_run_status($_GET['runId'], $_GET['stepRunId'] ?? null);
    http_response_code($result['status']);
    echo $result['body'];
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Could not call Zapq API'], JSON_THROW_ON_ERROR);
}
