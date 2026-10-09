<?php
declare(strict_types=1);

// No Composer or test framework required. All writable test data stays in the OS temp directory.
$root = dirname(__DIR__);
foreach (['pdo_sqlite', 'mbstring', 'curl'] as $extension) {
    if (!extension_loaded($extension)) {
        throw new RuntimeException('Missing PHP extension: ' . $extension);
    }
}

foreach (['public/index.php', 'public/run.php', 'public/status.php', 'public/state.php',
    'lib/database.php', 'lib/zapq.php', 'lib/config.php', 'lib/version.php',
    'README.md', 'docs/agent.md', '.github/workflows/check.yml', '.gitlab-ci.yml'] as $path) {
    if (!is_file($root . '/' . $path)) {
        throw new RuntimeException('Missing template file: ' . $path);
    }
}

$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$linted = 0;
foreach ($files as $file) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($relative, '.git/')) {
        continue;
    }

    if (preg_match('/\.(sqlite(?:-.*)?|db)$/i', $relative) ||
        preg_match('~^public/(?:lib|tests|docs|data)/~', $relative)) {
        throw new RuntimeException('Private data/code in template: ' . $relative);
    }

    if ($file->getExtension() === 'php') {
        passthru(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file->getPathname()), $status);
        if ($status !== 0) {
            exit($status);
        }

        $linted++;
    }
}

require __DIR__ . '/database.php';
require __DIR__ . '/contracts.php';
echo "Template OK: $linted PHP files linted; database and API contract checks passed.\n";
