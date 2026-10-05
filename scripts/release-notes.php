<?php

declare(strict_types=1);

// Build the text of a GitHub release from CHANGELOG.md.
//
//   php scripts/release-notes.php --version=2.0.8 [--existing=FILE]
//
// Prints the changelog sections since the previous release, followed by the
// release's existing text (GitHub's generated list of pull requests) folded
// away underneath. Running it on its own output replaces only the changelog
// part. .github/workflows/release-notes.yml runs it when a release is
// published.

namespace Lotgd\Scripts\ReleaseNotes;

const START = '<!-- changelog:start -->';
const END = '<!-- changelog:end -->';

/**
 * The changelog sections of a version and of every version listed after it
 * down to, but not including, the previous release, whose section must
 * follow.
 *
 * Versions that were never published as releases (2.0.6 and 2.0.7 before
 * 2.0.8) are part of the next release's notes this way.
 */
function changelogSections(string $changelog, string $version, ?string $previous): string
{
    $parts = preg_split('/^(?=## \[)/m', str_replace("\r\n", "\n", $changelog));
    $sections = [];
    $collecting = false;
    $reachedPrevious = false;
    foreach ($parts === false ? [] : $parts as $part) {
        if (preg_match('/^## \[([^\]]+)\]/', $part, $matches) !== 1) {
            continue;
        }
        $name = $matches[1];
        if (! $collecting) {
            $collecting = $name === $version;
        } elseif ($previous === null || $name === $previous) {
            $reachedPrevious = $name === $previous;
            break;
        }
        if ($collecting) {
            // The last section runs into the file's footer; stop at a rule.
            $sections[] = rtrim((string) preg_replace('/\n---\n.*\z/s', '', $part));
        }
    }

    if ($sections === []) {
        throw new \RuntimeException("CHANGELOG.md has no section for {$version}.");
    }
    // Without the previous release's heading there is no end, and every
    // older section would be published as part of this release.
    if ($previous !== null && ! $reachedPrevious) {
        throw new \RuntimeException("CHANGELOG.md has no section for the previous release {$previous} below {$version}.");
    }

    return implode("\n\n", $sections);
}

/**
 * The newest final release older than $version, from a list of tags.
 *
 * @param list<string> $tags Tag names such as v2.0.5 or v2.0.4-rc3
 */
function previousRelease(string $version, array $tags): ?string
{
    $previous = null;
    foreach ($tags as $tag) {
        $candidate = ltrim(trim($tag), 'v');
        if (preg_match('/^\d+\.\d+\.\d+$/', $candidate) !== 1 || version_compare($candidate, $version, '>=')) {
            continue;
        }
        if ($previous === null || version_compare($candidate, $previous, '>')) {
            $previous = $candidate;
        }
    }

    return $previous;
}

/**
 * The release text: the changelog sections first, the existing text folded
 * away below. A text this function produced before keeps everything outside
 * its changelog block.
 */
function releaseBody(string $sections, string $existingBody, string $version): string
{
    $block = START . "\n" . $sections . "\n\n"
        . "See [CHANGELOG.md](https://github.com/NB-Core/lotgd/blob/v{$version}/CHANGELOG.md) for earlier versions"
        . " and [UPGRADING.md](https://github.com/NB-Core/lotgd/blob/v{$version}/UPGRADING.md) before updating.\n"
        . END;

    $existingBody = str_replace("\r\n", "\n", $existingBody);
    $start = strpos($existingBody, START);
    $end = strpos($existingBody, END);
    if ($start !== false && $end !== false && $end > $start) {
        return substr($existingBody, 0, $start) . $block . substr($existingBody, $end + strlen(END));
    }

    if (trim($existingBody) === '') {
        return $block . "\n";
    }

    return $block . "\n\n<details>\n<summary>Merged pull requests</summary>\n\n"
        . trim($existingBody) . "\n\n</details>\n";
}

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    $options = getopt('', ['version:', 'existing:']);
    $version = is_string($options['version'] ?? null) ? ltrim($options['version'], 'v') : '';
    if ($version === '') {
        fwrite(STDERR, "Usage: php scripts/release-notes.php --version=X.Y.Z [--existing=FILE]\n");

        return 2;
    }

    $root = dirname(__DIR__);
    $changelog = file_get_contents($root . '/CHANGELOG.md');
    if ($changelog === false) {
        fwrite(STDERR, "Could not read CHANGELOG.md.\n");

        return 2;
    }

    $existing = '';
    if (is_string($options['existing'] ?? null)) {
        $read = file_get_contents($options['existing']);
        if ($read === false) {
            fwrite(STDERR, "Could not read {$options['existing']}.\n");

            return 2;
        }
        $existing = $read;
    }

    exec('git -C ' . escapeshellarg($root) . " tag --list 'v*'", $tags, $status);
    if ($status !== 0) {
        fwrite(STDERR, "Could not list the git tags.\n");

        return 2;
    }

    try {
        $sections = changelogSections($changelog, $version, previousRelease($version, $tags));
    } catch (\RuntimeException $exception) {
        fwrite(STDERR, $exception->getMessage() . "\n");

        return 1;
    }

    fwrite(STDOUT, releaseBody($sections, $existing, $version));

    return 0;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(main($argv));
}
