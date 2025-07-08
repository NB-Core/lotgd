<?php
// Lightweight loader for AJAX requests
if (!defined('AJAX_MODE')) {
    define('AJAX_MODE', true);
}

$cwd = getcwd();
chdir(__DIR__ . '/../');
require_once __DIR__ . '/../common.php';

// Reload the player account and allowed navigation like a normal page
\Lotgd\ForcedNavigation::doForcedNav(ALLOW_ANONYMOUS, OVERRIDE_FORCED_NAV);

chdir($cwd);
