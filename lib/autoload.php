<?php
/**
 * Simple project autoloader for classes using the Lotgd namespace.
 */
spl_autoload_register(function ($class) {
    $prefix = 'Lotgd\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relative = substr($class, $len);
    // map namespace separators to directory separators
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});
