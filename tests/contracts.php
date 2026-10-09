<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/zapq.php';

foreach (['', '../runs', 'https://example.invalid', "bad\nmethod"] as $method) {
    try {
        zapq_run_pipeline($method, '{}');
        throw new RuntimeException('Invalid method reached the API.');
    } catch (InvalidArgumentException $expected) {
        // Validate before reading a session or opening a connection.
    }
}

foreach (['', '{', "{'a':1}", '{"a":1} x', '[' . str_repeat('1,', 32768) . '1]'] as $input) {
    try {
        zapq_run_pipeline('demo', $input);
        throw new RuntimeException('Invalid or oversized JSON input reached the API.');
    } catch (InvalidArgumentException $expected) {
    }
}

foreach (['', "key\r\nInjected: header", 'key with spaces', str_repeat('a', 101)] as $key) {
    try {
        zapq_run_pipeline('demo', '{}', $key);
        throw new RuntimeException('Invalid idempotency key reached the API.');
    } catch (InvalidArgumentException $expected) {
    }
}

foreach (['', '../runs', str_repeat('a', 36), '11111111-1111-1111-1111-111111111111?x=1'] as $id) {
    try {
        zapq_run_status($id);
        throw new RuntimeException('Invalid run ID reached the API.');
    } catch (InvalidArgumentException $expected) {
    }
}

try {
    zapq_run_status('11111111-1111-1111-1111-111111111111', '');
    throw new RuntimeException('Invalid step ID reached the API.');
} catch (InvalidArgumentException $expected) {
}

try {
    zapq_api('GET', 'https://example.invalid/');
    throw new RuntimeException('External URL reached the API client.');
} catch (InvalidArgumentException $expected) {
}

try {
    zapq_context();
    throw new RuntimeException('Direct access without gateway context was accepted.');
} catch (RuntimeException $expected) {
    check($expected->getMessage() === 'Open this application through the Zapq gateway.', 'Missing session error.');
}

$config = require dirname(__DIR__) . '/lib/config.php';
check(is_string($config['api_method']), 'API method is explicit public configuration.');
check(in_array(require dirname(__DIR__) . '/lib/version.php', ['A', 'B'], true), 'Known example version.');
check((require __DIR__ . '/versions/A.php') === 'A', 'Version A fixture.');
check((require __DIR__ . '/versions/B.php') === 'B', 'Version B fixture.');
