<?php

declare(strict_types=1);

/**
 * Common bootstrap for AJAX operations. This file configures the
 * Jaxon library and registers callable functions used by the client
 * side scripts.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Jaxon\Jaxon;                      // Use the jaxon core class
use function Jaxon\jaxon;

// Load asynchronous configuration settings
require_once __DIR__ . '/settings.php';

global $jaxon;
// Get the Jaxon singleton object
$jaxon = jaxon();

// Set the Jaxon request processing URI
$jaxon->setOption('core.request.uri', '/async/process.php');
// Use empty prefix to get clean namespace: Lotgd.Async.Handler.*
$jaxon->setOption('core.prefix.class', '');

// Serve the Jaxon client runtime from this installation instead of the CDN
// that jaxon-core defaults to (AssetManager::JS_LIB_URL points at
// cdn.jsdelivr.net). Three reasons, in order of weight:
//
//   1. It carries a security control. async/js/lotgd.jaxon.js attaches the
//      async CSRF header, and its behaviour depends on how this runtime builds
//      its fetch call -- which is exactly what a file nobody here can read or
//      diff must not decide.
//   2. Supply chain. A third party serving executable code into every player's
//      browser is a dependency like any other and belongs in the tree, where a
//      change shows up in a diff.
//   3. Availability. A CDN outage or a blocked network takes the whole async
//      layer down.
//
// The version is pinned by the directory content, and tests/Async/check-jaxon-assets.sh
// verifies both integrity and freshness against upstream.
$jaxon->setOption('js.lib.uri', '/async/js/vendor/jaxon');

// Configure the Jaxon client library - disable auto-export since we manage our own JS files
$jaxon->setOption('js.app.export', false);
$jaxon->setOption('js.app.dir', __DIR__ . '/../js');
$jaxon->setOption('js.app.uri', '/async/js');

// DISABLE debug mode to prevent writeDebugMessage errors
$jaxon->setOption('core.debug.on', false);
$jaxon->setOption('core.debug.verbose', false);

/**
 * Register async handlers from the directory to keep the historical Jaxon
 * client export shape (Lotgd.Async.Handler.*) stable for runtime bridge code.
 *
 * Security note: async/process.php still enforces its allowlist before Jaxon
 * dispatch. The passkey class options below narrow Jaxon's callable surface as
 * defense in depth so helper/test seams are never exported or invokable.
 *
 * @var array<int, string> $passkeyRuntimeMethods
 */
$passkeyRuntimeMethods = [
    'beginRegistration',
    'finishRegistration',
    'beginAuthentication',
    'verifyAuthentication',
];

$jaxon->register(Jaxon::CALLABLE_DIR, __DIR__ . '/../../src/Lotgd/Async/Handler', [
    'namespace' => 'Lotgd\\Async\\Handler',
    'classes' => [
        // A debug echo that reports "AJAX test successful" into the notify
        // element. Useful locally, but it has no caller and no business being
        // invokable on a production install.
        'Lotgd\\Async\\Handler\\Commentary' => [
            'functions' => [
                'test' => [
                    'excluded' => true,
                ],
            ],
        ],
        'Lotgd\\Async\\Handler\\TwoFactorAuthPasskey' => [
            'export' => [
                'only' => $passkeyRuntimeMethods,
            ],
            'functions' => [
                'setService,setRepository' => [
                    'excluded' => true,
                ],
            ],
        ],
    ],
]);
