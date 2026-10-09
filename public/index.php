<?php
declare(strict_types=1);
require dirname(__DIR__) . '/lib/database.php';
$config = require dirname(__DIR__) . '/lib/config.php';
$version = require dirname(__DIR__) . '/lib/version.php';

header('Cache-Control: no-store');
$error = null;
$values = [];
try {
    $database = database();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        add_entry($database, (string)($_POST['value'] ?? ''));
        header('Location: ./', true, 303);
        exit;
    }

    $values = entries($database);
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    $error = $exception->getMessage();
} catch (Throwable $exception) {
    http_response_code(500);
    $error = 'Nie udało się odczytać lub zapisać danych.';
    error_log($exception->getMessage());
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
    <title>Notatki — przykład Zapq</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<main>
    <h1>Notatki</h1>
    <p>Wersja <strong id="version"><?= escape($version) ?></strong></p>
    <p>Wpisy są zachowywane w bazie aplikacji.</p>
    <?php if ($error !== null) { ?>
        <p role="alert"><?= escape($error) ?></p>
    <?php } ?>
    <form method="post">
        <?php if (isset($_SERVER['HTTP_X_ZAPQ_CSRF_TOKEN'])) { ?>
            <input type="hidden" name="<?= escape($_SERVER['HTTP_X_ZAPQ_CSRF_FIELD']) ?>" value="<?= escape($_SERVER['HTTP_X_ZAPQ_CSRF_TOKEN']) ?>">
        <?php } ?>
        <label for="value">Nowy wpis</label>
        <input id="value" name="value" maxlength="200" required>
        <button type="submit">Zapisz</button>
    </form>
    <?php if (isset($_SERVER['HTTP_X_ZAPQ_USER_LOGIN'])) { ?>
        <p>Zalogowano jako <?= escape(rawurldecode($_SERVER['HTTP_X_ZAPQ_USER_LOGIN'])) ?>.</p>
        <form id="pipeline" method="post" action="run.php">
            <input type="hidden" name="<?= escape($_SERVER['HTTP_X_ZAPQ_CSRF_FIELD']) ?>" value="<?= escape($_SERVER['HTTP_X_ZAPQ_CSRF_TOKEN']) ?>">
            <label for="method">Nazwa metody API potoku</label>
            <input id="method" name="method" value="<?= escape($config['api_method']) ?>" pattern="[a-zA-Z0-9_-]{1,128}" required>
            <button type="submit">Uruchom jako zalogowany użytkownik</button>
        </form>
        <p id="pipeline-message" role="status"></p>
        <div id="pipeline-status" hidden>
            <p><a id="run-link">Wynik JSON</a></p>
            <button id="refresh-run" type="button">Odśwież wynik</button>
            <pre id="run-result"></pre>
            <ul id="step-logs"></ul>
        </div>
        <p><a href="static.html">Przykład formularza z fetch</a></p>
        <script type="module" src="assets/pipeline.js"></script>
    <?php } ?>
    <ul>
        <?php foreach ($values as $value) { ?>
            <li><?= escape($value) ?></li>
        <?php } ?>
    </ul>
</main>
</body>
</html>
