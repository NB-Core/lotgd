<?php
// Lightweight loader for AJAX requests
if (!defined('AJAX_MODE')) {
    define('AJAX_MODE', true);
}

$cwd = getcwd();
chdir(__DIR__ . '/../');
require_once __DIR__ . '/../common.php';
chdir($cwd);
