<?php

declare(strict_types=1);

/**
 * Fail closed when Peanut Connect's declared PHP minimum drifts across its
 * public metadata, dependency contract, lockfile, or required CI lane.
 */

$root = dirname(__DIR__);
$failures = [];

$read = static function (string $relative) use ($root, &$failures): string {
    $path = $root . '/' . $relative;
    $contents = is_file($path) ? file_get_contents($path) : false;

    if ($contents === false) {
        $failures[] = sprintf('%s is missing or unreadable', $relative);

        return '';
    }

    return $contents;
};

$composer = json_decode($read('composer.json'), true);
$lock = json_decode($read('composer.lock'), true);

if (!is_array($composer)) {
    $failures[] = 'composer.json is not valid JSON';
} else {
    if (($composer['require']['php'] ?? null) !== '>=8.0') {
        $failures[] = 'composer.json require.php must be >=8.0';
    }
    if (($composer['config']['platform']['php'] ?? null) !== '8.0.0') {
        $failures[] = 'composer.json config.platform.php must be 8.0.0';
    }
}

if (!is_array($lock)) {
    $failures[] = 'composer.lock is not valid JSON';
} else {
    if (($lock['platform']['php'] ?? null) !== '>=8.0') {
        $failures[] = 'composer.lock platform.php must be >=8.0';
    }
    if (($lock['platform-overrides']['php'] ?? null) !== '8.0.0') {
        $failures[] = 'composer.lock platform-overrides.php must be 8.0.0';
    }
}

$declarationPatterns = [
    'peanut-connect.php' => '/^\s*\* Requires PHP:\s*8\.0\s*$/m',
    'readme.txt' => '/^Requires PHP:\s*8\.0\s*$/m',
];

foreach ($declarationPatterns as $file => $pattern) {
    if (!preg_match($pattern, $read($file))) {
        $failures[] = sprintf('%s must declare PHP 8.0', $file);
    }
}

$workflow = $read('.github/workflows/tests.yml');
$minimumJob = '';

if (preg_match('/^  php-minimum-tests:\s*$\R(?<body>(?:(?!^  [a-zA-Z0-9_-]+:\s*$).)*)/ms', $workflow, $match)) {
    $minimumJob = $match[0];
} else {
    $failures[] = '.github/workflows/tests.yml is missing required php-minimum-tests job';
}

$requiredWorkflowPatterns = [
    '/php-version:\s*["\']8\.0["\']/' => 'PHP 8.0 setup',
    '/composer install --no-interaction --prefer-dist/' => 'locked dependency installation',
    '/phpunit --testsuite=Unit,Property/' => 'minimum unit/property execution',
    '/php scripts\/verify-php-runtime\.php --expect-runtime=8\.0/' => 'runtime identity assertion',
];

foreach ($requiredWorkflowPatterns as $pattern => $description) {
    if (!preg_match($pattern, $minimumJob)) {
        $failures[] = sprintf('php-minimum-tests is missing %s', $description);
    }
}

if (($argv[1] ?? '') === '--expect-runtime=8.0' && PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION !== '8.0') {
    $failures[] = sprintf('expected the PHP 8.0 runtime, got %s', PHP_VERSION);
}

if ($failures !== []) {
    fwrite(STDERR, "PHP runtime declaration contract failed:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PHP runtime declaration contract passed (minimum 8.0).\n");
