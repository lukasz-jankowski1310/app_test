<?php
declare(strict_types=1);

function data_directory(): string
{
    $directory = getenv('ZAPQIO_APPLICATION_DATA_DIRECTORY');
    if ($directory === false || $directory === '') {
        throw new RuntimeException('Application data directory is not configured.');
    }

    return $directory;
}

function database(): PDO
{
    $database = new PDO('sqlite:' . data_directory() . '/state.sqlite');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('PRAGMA busy_timeout = 5000');
    $database->exec('PRAGMA synchronous = FULL');
    // The application owns its schema. The default SQLite rollback journal is retained.
    $database->exec('CREATE TABLE IF NOT EXISTS entries (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
    return $database;
}

function add_entry(PDO $database, string $value): void
{
    $value = trim($value);
    if ($value === '' || mb_strlen($value) > 200) {
        throw new InvalidArgumentException('Wpis musi mieć od 1 do 200 znaków.');
    }

    $statement = $database->prepare('INSERT INTO entries (value) VALUES (:value)');
    $statement->execute(['value' => $value]);
    $line = $value . "\n";
    if (file_put_contents(data_directory() . '/entries.txt', $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
        throw new RuntimeException('Could not write the example file.');
    }
}

function entries(PDO $database): array
{
    return $database->query('SELECT value FROM entries ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
}
