<?php

declare(strict_types=1);

use Lotgd\QA\SqlValueInterpolationCheck;

// Required directly rather than through Composer: the checker has no
// dependencies, and this lets CI run the guard without installing any, which
// is what keeps the job in the seconds range.
require_once dirname(__DIR__) . '/src/Lotgd/QA/SqlValueInterpolationCheck.php';

/**
 * Guard against new SQL value interpolation.
 *
 * Default mode scans the whole tree and is meant for a manual audit; the
 * codebase still carries historical findings, so it is not wired into CI.
 *
 * --changed-since=<ref> reports only violations on lines this branch actually
 * added relative to <ref>. That is what runs in CI: it costs a git diff and a
 * tokenizer pass over a handful of files, it blocks new cases, and it leaves
 * the existing ones alone — so touching a file for an unrelated reason does
 * not fail on code the change never looked at.
 */

$repositoryRoot = dirname(__DIR__);
$changedSince = null;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--changed-since=')) {
        $changedSince = substr($argument, strlen('--changed-since='));
        continue;
    }

    fwrite(STDERR, "Usage: check-sql-value-interpolation.php [--changed-since=<git-ref>]\n");
    exit(2);
}

$checker = new SqlValueInterpolationCheck();

if ($changedSince === null) {
    $violations = $checker->collectViolations($repositoryRoot);
    if ($violations === []) {
        echo "SQL value interpolation check passed.\n";
        exit(0);
    }

    $checker->report($violations);
    fwrite(STDERR, sprintf("\n%d finding(s) across the tree.\n", count($violations)));
    exit(1);
}

/**
 * Run a git command and return its stdout lines.
 *
 * @return list<string>
 */
$git = static function (string $arguments) use ($repositoryRoot): array {
    $command = sprintf('git -C %s %s 2>/dev/null', escapeshellarg($repositoryRoot), $arguments);
    $output = [];
    $status = 0;
    exec($command, $output, $status);
    if ($status !== 0) {
        fwrite(STDERR, "git command failed: $arguments\n");
        exit(1);
    }

    return $output;
};

// Resolve the merge base explicitly and diff the working tree against it,
// rather than using the `base...HEAD` shorthand. Both forms ignore commits
// that landed on the base branch meanwhile, but the shorthand also ignores
// everything that is not committed yet: run locally before committing, it
// would report a clean result for the very change being written. In CI the
// tree is clean and the two are identical.
$mergeBase = $git('merge-base ' . escapeshellarg($changedSince) . ' HEAD')[0] ?? '';
if ($mergeBase === '') {
    fwrite(STDERR, "Could not determine the merge base with $changedSince\n");
    exit(1);
}
$range = escapeshellarg($mergeBase);
$changedFiles = array_values(array_filter(
    $git('diff --name-only --diff-filter=d ' . $range),
    static fn (string $path): bool => str_ends_with($path, '.php')
));

if ($changedFiles === []) {
    echo "SQL value interpolation check passed (no PHP files changed).\n";
    exit(0);
}

/**
 * Line numbers this change added, per file. Parsed from a zero-context diff so
 * that only genuinely new or rewritten lines count.
 *
 * @var array<string, array<int, true>> $addedLines
 */
$addedLines = [];
$currentFile = null;
foreach ($git('diff --unified=0 ' . $range . ' -- ' . implode(' ', array_map('escapeshellarg', $changedFiles))) as $line) {
    if (str_starts_with($line, '+++ b/')) {
        $currentFile = substr($line, strlen('+++ b/'));
        continue;
    }

    if ($currentFile === null || !str_starts_with($line, '@@')) {
        continue;
    }

    if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,(\d+))? @@/', $line, $match) !== 1) {
        continue;
    }

    $start = (int) $match[1];
    $count = isset($match[2]) ? (int) $match[2] : 1;
    for ($offset = 0; $offset < $count; $offset++) {
        $addedLines[$currentFile][$start + $offset] = true;
    }
}

$violations = array_values(array_filter(
    $checker->collectViolations($repositoryRoot, $changedFiles),
    static fn (array $violation): bool => isset($addedLines[$violation['file']][$violation['line']])
));

if ($violations === []) {
    printf("SQL value interpolation check passed (%d changed PHP file(s)).\n", count($changedFiles));
    exit(0);
}

$checker->report($violations);
fwrite(STDERR, sprintf(
    "\n%d finding(s) on lines added by this change.\n"
    . "Pre-existing findings elsewhere are not reported; run without\n"
    . "--changed-since for a full audit.\n",
    count($violations)
));
exit(1);
