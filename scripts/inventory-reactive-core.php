<?php

/**
 * Print the reactive-core inventory (report only, always exits 0).
 *
 * Usage:
 *   php scripts/inventory-reactive-core.php [--category=<name>] [<extra dir> ...]
 *
 * Extra directories, such as a checkout of the modules repository, are
 * scanned in full in addition to the core tree.
 */

declare(strict_types=1);

use Lotgd\QA\ReactiveCoreInventory;

require dirname(__DIR__) . '/vendor/autoload.php';

$category = null;
$extraRoots = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--category=')) {
        $category = substr($argument, strlen('--category='));
        if (!in_array($category, ReactiveCoreInventory::CATEGORIES, true)) {
            fwrite(STDERR, "Unknown category '{$category}'. Known: " . implode(', ', ReactiveCoreInventory::CATEGORIES) . "\n");
            exit(2);
        }
        continue;
    }
    $resolved = realpath($argument);
    if ($resolved === false || !is_dir($resolved)) {
        fwrite(STDERR, "Not a directory: {$argument}\n");
        exit(2);
    }
    $extraRoots[] = $resolved;
}

exit((new ReactiveCoreInventory())->run(dirname(__DIR__), $extraRoots, $category));
