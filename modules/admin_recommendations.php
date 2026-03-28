<?php

declare(strict_types=1);

use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Translator;

/**
 * Surface operational security recommendations to superusers in the Grotto.
 *
 * This module intentionally keeps checks lightweight and read-only. It only
 * evaluates runtime/configuration state and prints short guidance messages.
 */
function admin_recommendations_getmoduleinfo(): array
{
    return [
        'name' => 'Admin Recommendations',
        'version' => '1.0.0',
        'author' => 'NB-Core',
        'category' => 'Administrative',
        'download' => 'core_module',
        'settings' => [
            'Admin Recommendations,title',
            'show_footer_summary' => 'Show recommendation summary in superuser footer,bool|1',
        ],
    ];
}

/**
 * Register the footer-superuser hook so recommendations appear in the Grotto.
 */
function admin_recommendations_install(): bool
{
    module_addhook('footer-superuser');

    return true;
}

function admin_recommendations_uninstall(): bool
{
    return true;
}

/**
 * Render a concise recommendation list for superusers.
 *
 * @param string               $hookname Hook currently being processed.
 * @param array<string, mixed> $args     Existing hook arguments.
 *
 * @return array<string, mixed>
 */
function admin_recommendations_dohook(string $hookname, array $args): array
{
    global $session;

    if ($hookname !== 'footer-superuser') {
        return $args;
    }

    if ((int) get_module_setting('show_footer_summary') !== 1) {
        return $args;
    }

    if (!isset($session['user']['superuser']) || ((int) $session['user']['superuser'] & SU_EDIT_CONFIG) !== SU_EDIT_CONFIG) {
        return $args;
    }

    Translator::tlschema('module_admin_recommendations');

    $recommendations = admin_recommendations_collect();
    if ($recommendations === []) {
        Translator::tlschema();

        return $args;
    }

    $output = Output::getInstance();
    $output->output('`n`n`b`^%s`0`b`n', Translator::translateInline('Admin Recommendations'));
    $output->output('`2%s`0`n', Translator::translateInline('Quick security/configuration checks found:'));
    foreach ($recommendations as $recommendation) {
        $output->output("`n`\$- `2%s`0", $recommendation);
    }
    $output->output("`n");

    Translator::tlschema();

    return $args;
}

/**
 * Build a compact recommendation list from dbconnect settings and runtime state.
 *
 * @return list<string>
 */
function admin_recommendations_collect(): array
{
    $settings = Settings::getInstance();
    $recommendations = [];
    $config = admin_recommendations_load_dbconnect();

    $securityHeadersEnabled = admin_recommendations_to_bool($config['SECURITY_HEADERS_ENABLED'] ?? true);
    if (!$securityHeadersEnabled) {
        $recommendations[] = Translator::translateInline(
            "SECURITY_HEADERS_ENABLED is disabled. Re-enable baseline security headers in dbconnect.php."
        );
    }

    $hstsEnabled = admin_recommendations_to_bool($config['SECURITY_HSTS_ENABLED'] ?? false);
    if (!$hstsEnabled) {
        $recommendations[] = Translator::translateInline(
            "HSTS is disabled (SECURITY_HSTS_ENABLED=false). Enable it once HTTPS/proxy setup is verified."
        );
    }

    $trustForwardedProto = admin_recommendations_to_bool($config['SECURITY_TRUST_FORWARDED_PROTO'] ?? false);
    $forwardedProtoPresent = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) || isset($_SERVER['HTTP_FORWARDED']);
    if ($forwardedProtoPresent && !$trustForwardedProto) {
        $recommendations[] = Translator::translateInline(
            "Reverse-proxy headers are present, but SECURITY_TRUST_FORWARDED_PROTO is false. Enable it and set SECURITY_TRUSTED_PROXIES."
        );
    }

    if ($trustForwardedProto && trim((string) ($config['SECURITY_TRUSTED_PROXIES'] ?? '')) === '') {
        $recommendations[] = Translator::translateInline(
            "SECURITY_TRUST_FORWARDED_PROTO is enabled but SECURITY_TRUSTED_PROXIES is empty. Add your proxy IP allowlist."
        );
    }

    if (!is_module_installed('twofactorauth') || !is_module_active('twofactorauth')) {
        $recommendations[] = Translator::translateInline(
            "Two-factor authentication module is not active. Install and activate 'twofactorauth' for admin/player account hardening."
        );
    }

    $datacacheEnabled = (int) ($config['DB_USEDATACACHE'] ?? 0) === 1;
    if ($datacacheEnabled) {
        $datacachePath = trim((string) ($config['DB_DATACACHEPATH'] ?? ''));
        if ($datacachePath === '' || !is_dir($datacachePath) || !is_writable($datacachePath)) {
            $recommendations[] = Translator::translateInline(
                "Data cache is enabled but DB_DATACACHEPATH is missing/not writable. Fix the path or disable DB_USEDATACACHE."
            );
        }
    }

    $serverUrl = trim((string) $settings->getSetting('serverurl', ''));
    if ($serverUrl !== '' && stripos($serverUrl, 'https://') !== 0) {
        $recommendations[] = Translator::translateInline(
            "serverurl does not start with https://. Set a canonical HTTPS URL in game settings."
        );
    }

    return $recommendations;
}

/**
 * Read dbconnect.php in array mode and gracefully handle legacy/non-array files.
 *
 * @return array<string, mixed>
 */
function admin_recommendations_load_dbconnect(): array
{
    $dbconnectPath = dirname(__DIR__) . '/dbconnect.php';
    if (!file_exists($dbconnectPath)) {
        return [];
    }

    try {
        /** @psalm-suppress UnresolvableInclude */
        $config = require $dbconnectPath;
    } catch (\Throwable) {
        return [];
    }

    return is_array($config) ? $config : [];
}

/**
 * Normalize typical bool-like config inputs ("1", "true", etc.) to bool.
 */
function admin_recommendations_to_bool(mixed $value): bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return (int) $value !== 0;
    }

    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}
