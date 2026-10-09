<?php
declare(strict_types=1);
$config = require dirname(__DIR__) . '/lib/config.php';

header('Cache-Control: no-store');
$method = $_GET['method'] ?? null;
if (!is_string($method) || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $method)) {
    $method = $config['api_method'];
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Uruchom potok — Zapq</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main class="wide">
    <p><a href="./">Powrót do notatek</a></p>
    <h1>Uruchom potok</h1>
    <?php if (!isset($_SERVER['HTTP_X_ZAPQ_USER_LOGIN'])) { ?>
        <p role="alert">Otwórz tę aplikację przez Zapq.</p>
    <?php } else { ?>
        <p>Wywołuje <code>POST /api/exec-pipeline/{metoda}</code> jako
            <strong><?= escape(rawurldecode($_SERVER['HTTP_X_ZAPQ_USER_LOGIN'])) ?></strong>.
            Wymaga prawa Pipelines.Run do potoku, a odczyt wyniku prawa Logs.View.</p>
        <form id="exec" method="post" action="run.php">
            <input type="hidden" name="<?= escape($_SERVER['HTTP_X_ZAPQ_CSRF_FIELD']) ?>" value="<?= escape($_SERVER['HTTP_X_ZAPQ_CSRF_TOKEN']) ?>">
            <!-- Repeating the same key and input returns the existing run instead of starting another. -->
            <input type="hidden" name="idempotencyKey" value="<?= bin2hex(random_bytes(16)) ?>">
            <label for="method">Metoda API</label>
            <div class="prefixed">
                <span>exec-pipeline/</span>
                <input id="method" name="method" value="<?= escape($method) ?>" pattern="[a-zA-Z0-9_\-]{1,128}" required autocomplete="off">
            </div>
            <label for="input">Wejście JSON</label>
            <textarea id="input" name="input" rows="8" maxlength="65536" spellcheck="false" required>{"source": "web-app"}</textarea>
            <button type="submit">Uruchom</button>
        </form>
        <p id="message" role="status"></p>

        <section id="run" hidden>
            <h2>Uruchomienie <code id="run-id"></code></h2>
            <p><span id="run-status" class="badge"></span> <span id="run-meta"></span></p>
            <p id="run-error" role="alert"></p>
            <p class="actions">
                <button id="refresh" type="button">Odśwież</button>
                <a id="run-json">Wynik JSON</a>
            </p>
            <h3>Wynik (lastOutput)</h3>
            <pre id="run-output"></pre>
            <h3>Kroki</h3>
            <div id="steps"></div>
            <details>
                <summary>Pełna odpowiedź API</summary>
                <pre id="run-raw"></pre>
            </details>
        </section>

        <h2>Otwórz istniejące uruchomienie</h2>
        <form id="lookup" method="get" action="status.php">
            <label for="run-lookup">ID uruchomienia (runId)</label>
            <input id="run-lookup" name="runId" required autocomplete="off"
                   pattern="[0-9a-fA-F]{8}-([0-9a-fA-F]{4}-){3}[0-9a-fA-F]{12}">
            <button type="submit">Pokaż</button>
        </form>
        <script type="module" src="assets/exec.js"></script>
    <?php } ?>
</main>
</body>
</html>
