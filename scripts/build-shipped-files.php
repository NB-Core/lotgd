<?php

declare(strict_types=1);

use Lotgd\Upgrade\ShippedFiles;

// Regenerate src/Lotgd/Upgrade/shipped-files.txt from the files git tracks.
//
//   php scripts/build-shipped-files.php          write the list
//   php scripts/build-shipped-files.php --check  exit 1 when it is out of date
//
// The workflow .github/workflows/shipped-files.yml runs this on every push to
// master and commits the result, so it rarely needs running by hand.
require dirname(__DIR__) . '/src/Lotgd/Upgrade/ShippedFiles.php';

$root = dirname(__DIR__);
$listing = shell_exec('git -C ' . escapeshellarg($root) . ' ls-files -z');
if (!is_string($listing) || $listing === '') {
    fwrite(STDERR, "git ls-files returned nothing; run this inside a git checkout.\n");
    exit(2);
}

$contents = ShippedFiles::render(ShippedFiles::fromListing(explode("\0", $listing)));
$current = is_file(ShippedFiles::LIST_FILE) ? (string) file_get_contents(ShippedFiles::LIST_FILE) : '';

if (in_array('--check', $argv, true)) {
    if ($current !== $contents) {
        fwrite(STDERR, "src/Lotgd/Upgrade/shipped-files.txt is out of date; run `composer shipped-files`.\n");
        exit(1);
    }
    fwrite(STDOUT, "src/Lotgd/Upgrade/shipped-files.txt is current.\n");
    exit(0);
}

if ($current === $contents) {
    fwrite(STDOUT, "src/Lotgd/Upgrade/shipped-files.txt is already current.\n");
    exit(0);
}
if (file_put_contents(ShippedFiles::LIST_FILE, $contents) === false) {
    fwrite(STDERR, "Could not write src/Lotgd/Upgrade/shipped-files.txt.\n");
    exit(2);
}
fwrite(STDOUT, "src/Lotgd/Upgrade/shipped-files.txt updated.\n");
