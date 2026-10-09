<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/database.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$originalDirectory = getenv('ZAPQIO_APPLICATION_DATA_DIRECTORY');
$testDirectory = sys_get_temp_dir() . '/zapq-template-' . bin2hex(random_bytes(8));
mkdir($testDirectory, 0700);
putenv('ZAPQIO_APPLICATION_DATA_DIRECTORY=' . $testDirectory);
try {
    $connection = database();
    check(entries($connection) === [], 'Fresh application must be empty.');
    $value = "Łódź <script>alert('x')</script> ' OR 1=1 --";
    add_entry($connection, $value);
    foreach (['', '   ', str_repeat('ą', 201)] as $invalid) {
        try {
            add_entry($connection, $invalid);
            throw new RuntimeException('Invalid entry was accepted.');
        } catch (InvalidArgumentException $expected) {
            // The rejected entry must not have changed either storage.
        }
    }

    $connection = null;
    $connection = database();
    check(entries($connection) === [$value], 'Reopening the database must preserve exact text.');
    check(file_get_contents($testDirectory . '/entries.txt') === $value . "\n", 'File must preserve exact text.');
    check($connection->query('PRAGMA journal_mode')->fetchColumn() === 'delete', 'Use rollback journal.');
    check($connection->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'SQLite integrity.');
    add_entry($connection, str_repeat('ą', 200));
    check(count(entries($connection)) === 2, 'Length is measured in Unicode characters.');
} finally {
    $connection = null;
    // Only known files in the unique directory created by this test are removed.
    foreach (['state.sqlite', 'state.sqlite-journal', 'entries.txt'] as $name) {
        if (is_file($testDirectory . '/' . $name)) {
            unlink($testDirectory . '/' . $name);
        }
    }

    rmdir($testDirectory);
    putenv($originalDirectory === false ? 'ZAPQIO_APPLICATION_DATA_DIRECTORY' :
        'ZAPQIO_APPLICATION_DATA_DIRECTORY=' . $originalDirectory);
}
