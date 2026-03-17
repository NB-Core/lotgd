<?php

declare(strict_types=1);

/**
 * Common bootstrap for AJAX operations. This file configures the
 * Jaxon library and registers callable functions used by the client
 * side scripts.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Jaxon\Jaxon;                      // Use the jaxon core class
use Lotgd\Async\Handler\TwoFactorAuthPasskey;
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

// Configure the Jaxon client library - disable auto-export since we manage our own JS files
$jaxon->setOption('js.app.export', false);
$jaxon->setOption('js.app.dir', __DIR__ . '/../js');
$jaxon->setOption('js.app.uri', '/async/js');

// DISABLE debug mode to prevent writeDebugMessage errors
$jaxon->setOption('core.debug.on', false);
$jaxon->setOption('core.debug.verbose', false);

/**
 * Register only the passkey async handler with an explicit method allowlist.
 *
 * Why this is required:
 * - Passkey endpoints mutate security-sensitive account state.
 * - Directory-wide registration can accidentally expose newly-added public methods.
 * - Explicit registration enforces least privilege and keeps the async attack surface
 *   constrained to the audited passkey challenge flow.
 */
$jaxon->register(Jaxon::CALLABLE_CLASS, TwoFactorAuthPasskey::class, [
    // Preserve the dot-delimited javascript namespace (Lotgd.Async.Handler.*).
    'namespace' => 'Lotgd\\Async\\Handler',
    // Security boundary: only these four methods are routable over async/process.php.
    'export' => [
        'only' => [
            'beginRegistration',
            'finishRegistration',
            'beginAuthentication',
            'verifyAuthentication',
        ],
    ],
]);

/**
 * Server-side sanity check for passkey export visibility in generated bootstrap script.
 *
 * Why this exists:
 * - Explicit method allowlists are the security boundary for passkey async handlers.
 * - Client-side bridge dispatch depends on Jaxon exporting the passkey namespace.
 * - If export generation regresses, we prefer a debug log hint during bootstrap instead
 *   of a runtime timeout/error that is harder to diagnose.
 *
 * This check is intentionally non-fatal and debug-only/log-only.
 */
$debugExportVerification = (($_ENV['LOTGD_DEBUG_JAXON_EXPORT_CHECK'] ?? '') === '1')
    || (($_SERVER['LOTGD_DEBUG_JAXON_EXPORT_CHECK'] ?? '') === '1');

if ($debugExportVerification) {
    $generatedScript = (string) $jaxon->getScript();
    if (strpos($generatedScript, 'TwoFactorAuthPasskey') === false) {
        error_log('[Jaxon][Passkey] Export verification failed: generated script is missing TwoFactorAuthPasskey namespace bindings.');
    }
}
