<?php

declare(strict_types=1);

namespace Lotgd\QA;

/**
 * Verify that the committed vendor/ holds exactly the production packages.
 *
 * vendor/ is committed so a shared-hosting install works from a plain
 * download, without Composer. That only stays true while vendor/ matches
 * composer.lock, and it only stays small and free of test tooling while no
 * development package ends up in it. The test and analysis tools live in
 * tools/ with their own vendor directory for exactly that reason.
 *
 * The check compares Composer's own records (composer.lock and
 * vendor/composer/installed.php) and the package directories on disk. It does
 * not compare the generated autoload files byte for byte: Composer versions
 * differ in how they write them, and that difference is not a finding.
 */
final class VendorProductionCheck
{
    /**
     * Run the check against a repository root and print the result.
     *
     * @param resource|null $out Stream for the success line; defaults to STDOUT
     * @param resource|null $err Stream for problems; defaults to STDERR
     *
     * @return int 0 when vendor/ is consistent, 1 otherwise
     */
    public function run(string $root, $out = null, $err = null): int
    {
        $out ??= STDOUT;
        $err ??= STDERR;

        $lockFile = $root . '/composer.lock';
        $installedFile = $root . '/vendor/composer/installed.php';
        if (!is_file($lockFile) || !is_file($installedFile)) {
            fwrite($err, "composer.lock or vendor/composer/installed.php is missing.\n");

            return 1;
        }

        $lock = json_decode((string) file_get_contents($lockFile), true);
        $installed = require $installedFile;
        if (!is_array($lock) || !is_array($installed)) {
            fwrite($err, "composer.lock or vendor/composer/installed.php could not be read.\n");

            return 1;
        }

        $problems = $this->findProblems($lock, $installed, $this->packageDirectories($root . '/vendor'));
        if ($problems === []) {
            fwrite($out, "vendor/ matches composer.lock and holds no development packages.\n");

            return 0;
        }

        fwrite($err, "vendor/ does not match a production install of composer.lock:\n");
        foreach ($problems as $problem) {
            fwrite($err, '  - ' . $problem . "\n");
        }
        fwrite($err, "Run `composer install --no-dev` and commit vendor/. Test and analysis tools belong in tools/composer.json.\n");

        return 1;
    }

    /**
     * Compare the lock file, Composer's install record and the package
     * directories found on disk.
     *
     * @param array<string,mixed> $lock        Decoded composer.lock
     * @param array<string,mixed> $installed   Contents of vendor/composer/installed.php
     * @param list<string>        $directories Package names found as vendor/<vendor>/<name>
     *
     * @return list<string> One message per problem, empty when consistent
     */
    public function findProblems(array $lock, array $installed, array $directories): array
    {
        $problems = [];

        foreach ($lock['packages-dev'] ?? [] as $package) {
            $problems[] = sprintf(
                'composer.lock has the development package %s; development requirements belong in tools/composer.json',
                $package['name'] ?? '?'
            );
        }

        $locked = [];
        foreach ($lock['packages'] ?? [] as $package) {
            $locked[(string) $package['name']] = $package;
        }

        $present = [];
        foreach ($installed['versions'] ?? [] as $name => $entry) {
            // Provided, replaced and root entries carry no install path.
            if (!is_array($entry) || !isset($entry['install_path']) || $name === ($installed['root']['name'] ?? null)) {
                continue;
            }
            $present[$name] = $entry;
            if (!empty($entry['dev_requirement'])) {
                $problems[] = sprintf('vendor/ has the development package %s', $name);
            }
        }

        foreach ($locked as $name => $package) {
            if (!isset($present[$name])) {
                $problems[] = sprintf('%s %s is locked but not installed in vendor/', $name, $package['version'] ?? '?');
                continue;
            }
            $lockedVersion = (string) ($package['version'] ?? '');
            $installedVersion = (string) ($present[$name]['pretty_version'] ?? '');
            if ($lockedVersion !== $installedVersion) {
                $problems[] = sprintf('%s is locked at %s but vendor/ has %s', $name, $lockedVersion, $installedVersion);
                continue;
            }
            $lockedReference = $package['dist']['reference'] ?? $package['source']['reference'] ?? null;
            $installedReference = $present[$name]['reference'] ?? null;
            if ($lockedReference !== null && $installedReference !== null && $lockedReference !== $installedReference) {
                $problems[] = sprintf('%s %s is locked at a different commit than vendor/ has', $name, $lockedVersion);
            }
        }

        foreach (array_keys($present) as $name) {
            if (!isset($locked[$name]) && empty($present[$name]['dev_requirement'])) {
                $problems[] = sprintf('vendor/ has %s, which composer.lock does not list', $name);
            }
        }

        foreach ($directories as $name) {
            if (!isset($present[$name])) {
                $problems[] = sprintf('vendor/%s exists but Composer did not install it; delete it', $name);
            }
        }
        foreach (array_keys($present) as $name) {
            if (!in_array($name, $directories, true)) {
                $problems[] = sprintf('vendor/%s is recorded as installed but its directory is missing', $name);
            }
        }

        return $problems;
    }

    /**
     * List the vendor/<vendor>/<name> directories, skipping Composer's own
     * vendor/bin and vendor/composer.
     *
     * @return list<string>
     */
    private function packageDirectories(string $vendorDir): array
    {
        $names = [];
        foreach (scandir($vendorDir) ?: [] as $vendor) {
            if ($vendor[0] === '.' || $vendor === 'bin' || $vendor === 'composer' || !is_dir($vendorDir . '/' . $vendor)) {
                continue;
            }
            foreach (scandir($vendorDir . '/' . $vendor) ?: [] as $package) {
                if ($package[0] !== '.' && is_dir($vendorDir . '/' . $vendor . '/' . $package)) {
                    $names[] = $vendor . '/' . $package;
                }
            }
        }

        return $names;
    }
}
