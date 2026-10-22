<?php
declare(strict_types=1);
require_once __DIR__ . '/database.php';

function webhook_configuration(): array
{
    // Provision this file in the persistent data volume, never in Git or public/.
    $file = data_directory() . '/webhook.json';
    if (!is_file($file)) {
        throw new RuntimeException('Webhook receiver is not configured.');
    }

    $configuration = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($configuration) || !is_string($configuration['webhookId'] ?? null) ||
        !is_array($configuration['secrets'] ?? null) || $configuration['secrets'] === []) {
        throw new RuntimeException('Invalid webhook configuration.');
    }

    foreach ($configuration['secrets'] as $secret) {
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('Invalid webhook signing secret.');
        }
    }

    return $configuration;
}

function webhook_signature_valid(string $body, string $header, array $secrets, int $now): bool
{
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        $part = trim($part);
        if (preg_match('/^t=([0-9]{1,12})$/D', $part, $match)) {
            if ($timestamp !== null) {
                return false;
            }

            $timestamp = $match[1];
        } else if (preg_match('/^v1=([0-9a-f]{64})$/iD', $part, $match)) {
            $signatures[] = strtolower($match[1]);
        }
    }

    if ($timestamp === null || abs($now - (int)$timestamp) > 300) {
        return false;
    }

    foreach ($secrets as $secret) {
        // Zapq uses the literal secret text as the HMAC key, not hex2bin(secret).
        $expected = hash_hmac('sha256', $timestamp . '.' . $body, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
    }

    return false;
}

function webhook_guid(mixed $value): bool
{
    return is_string($value) && preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $value) === 1;
}

function webhook_event(string $body, array $headers): array
{
    $event = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
    $data = is_array($event) ? ($event['data'] ?? null) : null;
    if (!is_array($event) || !webhook_guid($event['id'] ?? null) ||
        ($headers['HTTP_X_WEBHOOK_DELIVERY'] ?? '') !== $event['id'] ||
        ($event['event'] ?? '') !== 'pipelineRun.jobFinished' ||
        ($headers['HTTP_X_WEBHOOK_EVENT'] ?? '') !== $event['event'] ||
        ($event['apiVersion'] ?? '') !== '2026-07' || !is_array($data) ||
        !webhook_guid($data['runId'] ?? null) ||
        !in_array($data['status'] ?? null, ['waiting', 'executing', 'ok', 'error', 'needsAttention', 'cancelled'], true) ||
        !is_array($data['steps'] ?? null) || !array_is_list($data['steps']) ||
        (isset($data['lastOutput']) && !is_string($data['lastOutput']))) {
        throw new InvalidArgumentException('Invalid pipeline webhook.');
    }

    foreach ($data['steps'] as $step) {
        if (!is_array($step) || !webhook_guid($step['id'] ?? null) || !is_int($step['order'] ?? null) ||
            !in_array($step['status'] ?? null, ['waiting', 'dispatched', 'executing', 'ok', 'error', 'outcomeUnknown', 'cancelled'], true)) {
            throw new InvalidArgumentException('Invalid webhook step.');
        }
    }

    $occurredAt = $event['occurredAt'] ?? '';
    if (!is_string($occurredAt) ||
        !preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,7}))?Z$/D', $occurredAt, $match)) {
        throw new InvalidArgumentException('Invalid webhook timestamp.');
    }

    // Fixed precision sorts correctly even when an older delivery is retried after a newer one.
    $event['sortTime'] = $match[1] . '.' . str_pad($match[2] ?? '', 7, '0') . 'Z';
    return $event;
}

function webhook_database(): PDO
{
    $database = database();
    $database->exec('CREATE TABLE IF NOT EXISTS application_runs (
        run_id TEXT PRIMARY KEY, user_id TEXT NOT NULL, method TEXT NOT NULL, created_at TEXT NOT NULL)');
    $database->exec('CREATE TABLE IF NOT EXISTS webhook_deliveries (
        delivery_id TEXT PRIMARY KEY, run_id TEXT NOT NULL, occurred_at TEXT NOT NULL,
        received_at TEXT NOT NULL, body TEXT NOT NULL)');
    $database->exec('CREATE INDEX IF NOT EXISTS webhook_run_time ON webhook_deliveries(run_id, occurred_at)');
    return $database;
}

function register_application_run(PDO $database, string $runId, string $userId, string $method): void
{
    if (!webhook_guid($runId) || !webhook_guid($userId)) {
        throw new InvalidArgumentException('Invalid run owner.');
    }

    $statement = $database->prepare('INSERT INTO application_runs (run_id, user_id, method, created_at)
        VALUES (?, ?, ?, ?) ON CONFLICT(run_id) DO NOTHING');
    $statement->execute([strtolower($runId), strtolower($userId), $method, gmdate('Y-m-d\TH:i:s\Z')]);
}

function store_webhook(PDO $database, array $event, string $body): bool
{
    // This durable receipt is the demo's business action. A retry cannot execute it twice.
    // The callback can arrive before run.php has finished registering its owner.
    $statement = $database->prepare('INSERT INTO webhook_deliveries
        (delivery_id, run_id, occurred_at, received_at, body) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(delivery_id) DO NOTHING');
    $statement->execute([$event['id'], strtolower($event['data']['runId']), $event['sortTime'], gmdate('Y-m-d\TH:i:s\Z'), $body]);
    if ($statement->rowCount() === 1) {
        return true;
    }

    $existing = $database->prepare('SELECT body FROM webhook_deliveries WHERE delivery_id = ?');
    $existing->execute([$event['id']]);
    if ($existing->fetchColumn() !== $body) {
        throw new InvalidArgumentException('Delivery ID already has a different payload.');
    }

    return false;
}

function application_run_result(PDO $database, string $runId, string $userId): ?array
{
    if (!webhook_guid($runId)) {
        throw new InvalidArgumentException('Invalid run ID.');
    }

    // Knowing a runId is not permission to read another user's result.
    $statement = $database->prepare('SELECT method, created_at FROM application_runs WHERE run_id = ? AND user_id = ?');
    $statement->execute([strtolower($runId), strtolower($userId)]);
    $run = $statement->fetch(PDO::FETCH_ASSOC);
    if ($run === false) {
        return null;
    }

    $statement = $database->prepare('SELECT body, received_at FROM webhook_deliveries WHERE run_id = ?
        ORDER BY occurred_at DESC, rowid DESC LIMIT 1');
    $statement->execute([strtolower($runId)]);
    $delivery = $statement->fetch(PDO::FETCH_ASSOC);
    $event = $delivery === false ? null : json_decode($delivery['body'], true, 64, JSON_THROW_ON_ERROR);
    $result = $event['data'] ?? ['runId' => $runId, 'status' => 'waiting', 'steps' => []];
    $result['status'] = ucfirst($result['status']);
    foreach ($result['steps'] as &$step) {
        $step['status'] = ucfirst($step['status']);
    }

    unset($step);
    return $result + [
        'apiMethod' => $run['method'], 'createdAt' => $run['created_at'],
        'webhookReceived' => $event !== null, 'receivedAt' => $delivery['received_at'] ?? null,
        'webhook' => $event
    ];
}
