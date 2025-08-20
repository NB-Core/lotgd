<?php
// Debug script to check what Jaxon generates
require_once __DIR__ . '/async/common/jaxon.php';

echo "=== Jaxon Configuration Debug ===\n";
echo "Request URI: " . $jaxon->getOption('core.request.uri') . "\n";
echo "Class Prefix: " . $jaxon->getOption('core.prefix.class') . "\n";
echo "App Export: " . ($jaxon->getOption('js.app.export') ? 'true' : 'false') . "\n";

echo "\n=== Generated JavaScript ===\n";
echo $jaxon->getJs();

echo "\n=== Generated Script ===\n";
echo $jaxon->getScript();