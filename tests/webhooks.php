<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/webhooks.php';

$secret = str_repeat('a1', 32);
$now = 1792666800;
$runId = '11111111-1111-1111-1111-111111111111';
$owner = '22222222-2222-2222-2222-222222222222';
$deliveryId = '33333333-3333-3333-3333-333333333333';
$envelope = [
    'id' => $deliveryId, 'event' => 'pipelineRun.jobFinished', 'occurredAt' => '2026-10-22T11:00:00.1234567Z',
    'apiVersion' => '2026-07', 'tenant' => 'test',
    'data' => ['runId' => $runId, 'status' => 'ok', 'lastOutput' => "Łódź\n\"result\"", 'steps' => [
        ['id' => '44444444-4444-4444-4444-444444444444', 'order' => 0, 'status' => 'ok'],
        ['id' => '55555555-5555-5555-5555-555555555555', 'order' => 1, 'status' => 'ok']
    ]]
];
$body = json_encode($envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$headers = ['HTTP_X_WEBHOOK_DELIVERY' => $deliveryId, 'HTTP_X_WEBHOOK_EVENT' => $envelope['event']];
$signature = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $body, $secret);
check(webhook_signature_valid($body, $signature, [$secret], $now), 'Exact UTF-8 body signature.');
check(!webhook_signature_valid($body . ' ', $signature, [$secret], $now), 'Altered body must fail.');
check(!webhook_signature_valid($body, $signature, [str_repeat('b', 64)], $now), 'Wrong secret must fail.');
check(!webhook_signature_valid($body, $signature, [$secret], $now + 301), 'Old timestamp must fail.');
check(!webhook_signature_valid($body, $signature, [$secret], $now - 301), 'Future timestamp must fail.');
check(!webhook_signature_valid($body, $signature . ',t=' . $now, [$secret], $now), 'Ambiguous timestamp must fail.');
check(!webhook_signature_valid($body, '', [$secret], $now), 'Missing signature must fail.');
check(webhook_signature_valid($body, 'v1=' . str_repeat('0', 64) . ',' . $signature,
    [str_repeat('b', 64), $secret], $now), 'Rotation supports multiple secrets and v1 signatures.');

$event = webhook_event($body, $headers);
check($event['sortTime'] === $envelope['occurredAt'], 'Preserve seven fractional digits.');
foreach (['null', '[]', '"text"', '{}', '{', str_replace('"ok"', '"invalid"', $body)] as $invalid) {
    try {
        webhook_event($invalid, $headers);
        throw new RuntimeException('Malformed event was accepted.');
    } catch (InvalidArgumentException | JsonException $expected) {
    }
}

try {
    webhook_event($body, ['HTTP_X_WEBHOOK_DELIVERY' => $runId, 'HTTP_X_WEBHOOK_EVENT' => $envelope['event']]);
    throw new RuntimeException('Mismatched delivery ID was accepted.');
} catch (InvalidArgumentException $expected) {
}

$originalDirectory = getenv('ZAPQIO_APPLICATION_DATA_DIRECTORY');
$testDirectory = sys_get_temp_dir() . '/zapq-webhooks-' . bin2hex(random_bytes(8));
mkdir($testDirectory, 0700);
putenv('ZAPQIO_APPLICATION_DATA_DIRECTORY=' . $testDirectory);
try {
    $connection = webhook_database();
    check(store_webhook($connection, $event, $body), 'Callback before registration is stored.');
    check(application_run_result($connection, $runId, $owner) === null, 'Unregistered result is private.');
    register_application_run($connection, $runId, $owner, 'demo');
    check(!store_webhook($connection, $event, $body), 'Retry does not repeat the business action.');
    check((int)$connection->query('SELECT COUNT(*) FROM webhook_deliveries')->fetchColumn() === 1, 'One durable receipt.');
    check(application_run_result($connection, $runId, $runId) === null, 'Another user cannot read the result.');
    register_application_run($connection, $runId, $runId, 'demo');
    check(application_run_result($connection, $runId, $runId) === null, 'Duplicate registration cannot change ownership.');

    $older = $envelope;
    $older['id'] = '66666666-6666-6666-6666-666666666666';
    $older['occurredAt'] = '2026-10-22T11:00:00Z';
    $older['data']['status'] = 'executing';
    $older['data']['steps'][1]['status'] = 'waiting';
    $oldBody = json_encode($older, JSON_THROW_ON_ERROR);
    $oldEvent = webhook_event($oldBody, ['HTTP_X_WEBHOOK_DELIVERY' => $older['id'], 'HTTP_X_WEBHOOK_EVENT' => $older['event']]);
    check(store_webhook($connection, $oldEvent, $oldBody), 'Out-of-order event is recorded.');
    $connection = null;
    $connection = webhook_database();
    $result = application_run_result($connection, strtoupper($runId), $owner);
    check($result['status'] === 'Ok', 'Late retry cannot overwrite a newer whole-pipeline status.');
    check($result['webhook']['data'] === $envelope['data'], 'Raw whole-pipeline status, steps and output survive reopening.');
    check($result['lastOutput'] === $envelope['data']['lastOutput'], 'Exact Unicode output.');
    check($result['webhookReceived'] === true, 'Distinguish a real callback from waiting.');

    $pendingId = '77777777-7777-7777-7777-777777777777';
    register_application_run($connection, $pendingId, $owner, 'demo');
    check(application_run_result($connection, $pendingId, $owner)['webhookReceived'] === false, 'No invented completion before callback.');
    check($connection->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Webhook database integrity.');
} finally {
    $connection = null;
    foreach (['state.sqlite', 'state.sqlite-journal'] as $name) {
        if (is_file($testDirectory . '/' . $name)) {
            unlink($testDirectory . '/' . $name);
        }
    }

    rmdir($testDirectory);
    putenv($originalDirectory === false ? 'ZAPQIO_APPLICATION_DATA_DIRECTORY' :
        'ZAPQIO_APPLICATION_DATA_DIRECTORY=' . $originalDirectory);
}
