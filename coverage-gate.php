<?php
/**
 * Peanut Ecosystem — Coverage Gate
 * --------------------------------
 * Portable Clover XML coverage threshold checker. Reused unchanged across every
 * PHP project in the ecosystem so the coverage floor is defined identically
 * everywhere (the whole point of the testing standard).
 *
 * Usage:
 *   php coverage-gate.php <clover.xml> <min-percent>
 *
 * Example (CI):
 *   php coverage-gate.php coverage/clover.xml 50
 *
 * Exit codes:
 *   0  coverage >= threshold
 *   1  coverage <  threshold        (fails the build — this is the gate)
 *   2  usage / file / parse error
 *
 * Why a script instead of a phpunit setting: PHPUnit 10 dropped the built-in
 * "fail under N%" option, so the ecosystem standardises on this tiny, dependency-free
 * checker. It reads the <metrics> totals Clover emits and computes
 * covered statements / total statements.
 */

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "usage: php coverage-gate.php <clover.xml> <min-percent>\n");
    exit(2);
}

$file = $argv[1];
$min  = (float) $argv[2];

if (!is_file($file)) {
    fwrite(STDERR, "coverage-gate: file not found: {$file}\n");
    fwrite(STDERR, "  (did the test step actually emit --coverage-clover?)\n");
    exit(2);
}

$xml = @simplexml_load_file($file);
if ($xml === false) {
    fwrite(STDERR, "coverage-gate: could not parse XML: {$file}\n");
    exit(2);
}

// Clover puts cumulative totals in the LAST <metrics> under <project>.
$metricsNodes = $xml->xpath('//project/metrics');
if (!$metricsNodes) {
    // Some Clover variants only have file-level metrics; sum them.
    $metricsNodes = $xml->xpath('//metrics');
}
if (!$metricsNodes) {
    fwrite(STDERR, "coverage-gate: no <metrics> found in {$file}\n");
    exit(2);
}

$statements = 0;
$covered    = 0;
foreach ($metricsNodes as $m) {
    $statements += (int) ($m['statements'] ?? 0);
    $covered    += (int) ($m['coveredstatements'] ?? 0);
}

if ($statements === 0) {
    fwrite(STDERR, "coverage-gate: 0 statements measured — the suite likely collected no tests.\n");
    exit(2);
}

$pct = round($covered / $statements * 100, 2);

$line = sprintf(
    "coverage-gate: %.2f%% (%d/%d statements) — threshold %.2f%%\n",
    $pct, $covered, $statements, $min
);

if ($pct + 1e-9 < $min) {
    fwrite(STDERR, "FAIL  {$line}");
    exit(1);
}

fwrite(STDOUT, "PASS  {$line}");
exit(0);
