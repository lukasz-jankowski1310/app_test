<?php
declare(strict_types=1);

function zapq_context(): array
{
    $token = $_SERVER['HTTP_X_ZAPQ_ACCESS_TOKEN'] ?? '';
    if ($token === '') {
        throw new RuntimeException('Open this application through the Zapq gateway.');
    }

    return [
        'application_id' => $_SERVER['HTTP_X_ZAPQ_APPLICATION_ID'],
        'application_url' => $_SERVER['HTTP_X_ZAPQ_APPLICATION_URL'],
        'api_url' => $_SERVER['HTTP_X_ZAPQ_API_URL'],
        'internal_api_url' => $_SERVER['HTTP_X_ZAPQ_INTERNAL_API_URL'],
        'user_id' => $_SERVER['HTTP_X_ZAPQ_USER_ID'],
        'login' => rawurldecode($_SERVER['HTTP_X_ZAPQ_USER_LOGIN']),
        'token' => $token,
        'csrf_token' => $_SERVER['HTTP_X_ZAPQ_CSRF_TOKEN'],
        'csrf_field' => $_SERVER['HTTP_X_ZAPQ_CSRF_FIELD'],
        'csrf_header' => $_SERVER['HTTP_X_ZAPQ_CSRF_HEADER'],
        'data_directory' => getenv('ZAPQIO_APPLICATION_DATA_DIRECTORY')
    ];
}

function zapq_run_pipeline(string $method, mixed $input): array
{
    if (!preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $method)) {
        throw new InvalidArgumentException('Invalid API method name.');
    }

    return zapq_api('POST', 'exec-pipeline/' . rawurlencode($method), $input);
}

function zapq_run_status(string $runId, ?string $stepRunId = null): array
{
    foreach (array_filter([$runId, $stepRunId], fn($id) => $id !== null) as $id) {
        if (!preg_match('/^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/iD', $id)) {
            throw new InvalidArgumentException('Invalid run or step ID.');
        }
    }

    $path = 'runs/' . $runId;
    if ($stepRunId !== null) {
        $path .= '/steps/' . $stepRunId . '/logs';
    }

    return zapq_api('GET', $path);
}

function zapq_api(string $method, string $path, mixed $input = null): array
{
    // Internal routes only; never accept a complete URL from a form or follow redirects with a JWT.
    if (!preg_match('~^(?:exec-pipeline/[a-zA-Z0-9_-]{1,128}|runs/[0-9a-f-]{36}(?:/steps/[0-9a-f-]{36}/logs)?)$~iD', $path) ||
        !in_array($method, ['GET', 'POST'], true)) {
        throw new InvalidArgumentException('Invalid API request.');
    }

    $context = zapq_context();
    $request = curl_init($context['internal_api_url'] . $path);
    curl_setopt_array($request, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $context['token']],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 15
    ]);
    if ($method === 'POST') {
        curl_setopt($request, CURLOPT_POSTFIELDS, json_encode($input, JSON_THROW_ON_ERROR));
    }

    try {
        $body = curl_exec($request);
        if ($body === false) {
            throw new RuntimeException('Zapq API is unavailable.');
        }

        return ['status' => curl_getinfo($request, CURLINFO_RESPONSE_CODE), 'body' => $body];
    } finally {
        curl_close($request);
    }
}
