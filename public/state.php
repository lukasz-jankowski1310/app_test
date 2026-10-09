<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD', 'POST'], true)) {
        header('Allow: GET, HEAD, POST');
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed'], JSON_THROW_ON_ERROR);
        exit;
    }

    $database = database();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        add_entry($database, (string)($_POST['value'] ?? ''));
    }

    $file = data_directory() . '/entries.txt';
    echo json_encode([
        'version' => require dirname(__DIR__) . '/lib/version.php',
        'application_id' => getenv('ZAPQIO_APPLICATION_ID'),
        'values' => entries($database),
        'file' => is_file($file) ? file_get_contents($file) : '',
        'sqlite_version' => $database->query('SELECT sqlite_version()')->fetchColumn(),
        'journal_mode' => $database->query('PRAGMA journal_mode')->fetchColumn(),
        'integrity' => $database->query('PRAGMA integrity_check')->fetchColumn(),
    ], JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(500);
    error_log($exception->getMessage());
    echo json_encode(['error' => 'Could not read or write application data'], JSON_THROW_ON_ERROR);
}
