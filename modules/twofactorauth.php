<?php

declare(strict_types=1);

require_once __DIR__ . '/TwoFactorAuth/TwoFactorAuthService.php';

use Lotgd\Http;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\GameLog;
use Lotgd\Redirect;
use Lotgd\Translator;

/**
 * Two-step login module using TOTP challenges after password verification.
 */
function twofactorauth_getmoduleinfo(): array
{
    return [
        'name' => 'Two Factor Auth',
        'version' => '1.0.0',
        'author' => 'OpenAI Assistant',
        'category' => 'Security',
        'download' => 'core_module',
        'settings' => [
            'Two Factor Auth Settings,title',
            'issuer_name' => 'Issuer label shown in authenticator apps,text|Legend of the Green Dragon',
            'token_digits' => 'TOTP token length,int|6',
            'period_seconds' => 'TOTP period in seconds,int|30',
            'window' => 'Allowed drift window in timesteps (±window),int|1',
            'max_attempts' => 'Maximum failed attempts before cooldown,int|5',
            'lock_seconds' => 'Cooldown duration in seconds after max failures,int|120',
            'disable_link_ttl_minutes' => 'Disable-via-email link time-to-live (minutes),int|15',
            'require_verified_email' => 'Require account to have verified email before enabling 2FA,bool|0',
            'qr_provider_endpoint' => 'Optional QR provider endpoint used to render enrollment codes,text|https://api.qrserver.com/v1/create-qr-code/',
            'qr_code_size' => 'Enrollment QR code width/height in pixels,int|220',
        ],
        'prefs' => [
            'Two Factor Auth Preferences,title',
            'enabled' => 'Is TOTP enabled for this account?,bool|0',
            'secret_encrypted' => 'Encrypted TOTP secret,viewonly',
            'temp_secret_encrypted' => 'Temporary setup secret awaiting confirmation,viewonly',
            'verified_at' => 'Unix time of first successful setup verification,int|0',
            'last_used_timestep' => 'Last accepted timestep to prevent token replay,int|0',
            'backup_codes_hash' => 'Optional backup code hash,viewonly',
            'pending_challenge' => 'Whether login is currently pending second factor,bool|0',
            'pending_since' => 'Unix time when the challenge was started,int|0',
            'failed_attempts' => 'Failed challenge attempts for current pending session,int|0',
            'locked_until' => 'Unix time until next allowed attempt,int|0',
            'disable_token_hash' => 'Hash of active disable token,viewonly',
            'disable_token_expires' => 'Unix expiry for disable token,int|0',
            'disable_token_uri' => 'Whitelisted disable confirmation URI,viewonly',
        ],
    ];
}

function twofactorauth_install(): bool
{
    // Run late so our restorepage/challenge redirect wins over other modules mutating login destination.
    module_addhook_priority('player-login', 1000);
    module_addhook('player-logout');
    module_addhook('everyhit');
    module_addhook('everyheader');

    return true;
}

function twofactorauth_uninstall(): bool
{
    return true;
}

function twofactorauth_dohook(string $hookname, array $args): array
{
    global $session;

    switch ($hookname) {
        case 'player-login':
            if (!isset($session['user']['acctid'])) {
                break;
            }

            if ((int) get_module_pref('enabled') !== 1) {
                twofactorauth_clear_pending_state();
                break;
            }

            set_module_pref('pending_challenge', 1);
            set_module_pref('pending_since', time());
            set_module_pref('failed_attempts', 0);
            set_module_pref('locked_until', 0);

            $session['twofactorauth_pending'] = true;

            // Force a canonical relative restore target to avoid absolute-URL/whitespace variants causing badnav mismatches.
            $challengeUrl = 'runmodule.php?module=twofactorauth&op=challenge';
            $session['user']['restorepage'] = $challengeUrl;

            // Whitelist the immediate post-login challenge target so the initial redirect cannot land on badnav.
            if (!isset($session['allowednavs']) || !is_array($session['allowednavs'])) {
                $session['allowednavs'] = [];
            }
            Nav::add('', $challengeUrl);

            // Mirror into the account payload so the forced-nav bootstrap has the challenge URL persisted for this login handoff.
            $session['user']['allowednavs'] = serialize($session['allowednavs']);
            break;

        case 'player-logout':
            twofactorauth_clear_pending_state();
            break;

        case 'everyheader':
            $script = (string) ($args['script'] ?? '');
            if ($script === 'prefs') {
                Translator::tlschema('module_twofactorauth');
                Nav::add('Security');
                Nav::add('Two-factor authentication', 'runmodule.php?module=twofactorauth&op=setup');
                Translator::tlschema();
            }
            break;

        case 'everyhit':
            if (!($session['loggedin'] ?? false)) {
                break;
            }

            if ((int) get_module_pref('pending_challenge') !== 1) {
                break;
            }

            $confirmUri = (string) get_module_pref('disable_token_uri');
            $allowed = TwoFactorAuthService::buildAllowedChallengeNavs($confirmUri !== '' ? $confirmUri : null);

            $session['allowednavs'] = [];
            foreach ($allowed as $uri) {
                $session['allowednavs'][$uri] = true;
                $session['allowednavs'][str_replace(' ', '%20', $uri)] = true;
            }

            $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            if ($requestUri !== '' && str_starts_with($requestUri, '/')) {
                $requestUri = ltrim($requestUri, '/');
            }

            // Normalize to script+query so matching tolerates host/path differences.
            $uriPath = (string) parse_url($requestUri, PHP_URL_PATH);
            $uriQuery = (string) parse_url($requestUri, PHP_URL_QUERY);
            $normalizedRequestUri = $uriPath . ($uriQuery !== '' ? ('?' . $uriQuery) : '');

            if (!TwoFactorAuthService::isUriAllowed($normalizedRequestUri, $allowed)) {
                Redirect::redirect('runmodule.php?module=twofactorauth&op=challenge', '2FA jail redirect');
            }
            break;
    }

    return $args;
}

function twofactorauth_run(): void
{
    global $session;

    $output = Output::getInstance();
    $op = (string) Http::get('op');
    if ($op === '') {
        $op = 'challenge';
    }

    Translator::tlschema('module_twofactorauth');

    if ($op === 'setup') {
        Header::pageHeader('Two-factor authentication setup');
        twofactorauth_render_setup($output);
        Footer::pageFooter();
        Translator::tlschema();

        return;
    }

    if (!($session['loggedin'] ?? false)) {
        Redirect::redirect('index.php?op=timeout', '2FA endpoint without login');
    }

    Header::pageHeader('Two-factor authentication challenge');

    Nav::add('Navigation');
    Nav::add('Logout', 'login.php?op=logout');

    if ($op === 'verify') {
        twofactorauth_handle_challenge_verification($output);
    } elseif ($op === 'disable_email') {
        twofactorauth_handle_disable_via_email($output);
    } elseif ($op === 'confirm_disable') {
        twofactorauth_handle_disable_confirmation($output);
    } else {
        twofactorauth_render_challenge($output);
    }

    Footer::pageFooter();
    Translator::tlschema();
}

/**
 * Render and process the user-facing setup page in preferences.
 */
function twofactorauth_render_setup(Output $output): void
{
    global $session;

    $enabled = (int) get_module_pref('enabled') === 1;
    $requireVerified = (int) get_module_setting('require_verified_email') === 1;
    $email = (string) ($session['user']['emailaddress'] ?? '');

    Nav::add('Navigation');
    Nav::add('Return to preferences', 'prefs.php');

    $output->output('`bTwo-factor authentication`b`n');

    if ($requireVerified && strpos($email, '@') === false) {
        $output->output('Your account needs a valid email address before this feature can be enabled.`n');

        return;
    }

    if ($enabled) {
        $output->output('Two-factor authentication is currently enabled on your account.`n');
        $output->output('If you lose access to your authenticator app, use the login challenge page to request email recovery.`n');

        return;
    }

    $setupOp = (string) Http::get('setupop');
    $cryptoKey = twofactorauth_signing_key();

    if ($setupOp === 'start') {
        $secret = TwoFactorAuthService::generateSecret();
        set_module_pref('temp_secret_encrypted', TwoFactorAuthService::encryptSecret($secret, $cryptoKey));
    }

    $tempSecret = TwoFactorAuthService::decryptSecret((string) get_module_pref('temp_secret_encrypted'), $cryptoKey);
    if ($tempSecret === '') {
        Nav::add('Actions');
        Nav::add('Begin setup', 'runmodule.php?module=twofactorauth&op=setup&setupop=start');
        $output->output('You have not yet enrolled a device. Start setup to generate your TOTP secret.`n');

        return;
    }

    $issuer = (string) get_module_setting('issuer_name');
    $digits = (int) get_module_setting('token_digits');
    $period = (int) get_module_setting('period_seconds');
    $account = (string) ($session['user']['login'] ?? 'player');

    $otpauthUri = TwoFactorAuthService::buildOtpAuthUri($issuer, $account, $tempSecret, $digits, $period);
    $qrProviderEndpoint = trim((string) get_module_setting('qr_provider_endpoint'));
    $qrCodeSize = max(120, (int) get_module_setting('qr_code_size'));

    $output->output('Scan the QR code in your authenticator app, or enter the secret manually.`n');
    $output->output('Manual secret: `^%s`0`n', $tempSecret);

    if ($qrProviderEndpoint !== '') {
        $qrCodeUrl = TwoFactorAuthService::buildQrCodeUrl($qrProviderEndpoint, $otpauthUri, $qrCodeSize);
        // Show a scannable QR image while also retaining manual setup options.
        rawoutput("<div class='twofactorauth-qr'><img src='" . htmlspecialchars($qrCodeUrl, ENT_QUOTES, 'UTF-8') . "' alt='" . htmlspecialchars(translate_inline('Authenticator enrollment QR code'), ENT_QUOTES, 'UTF-8') . "' width='" . (int) $qrCodeSize . "' height='" . (int) $qrCodeSize . "'></div>");
    }

    $output->output('Enrollment URI (copy/paste if needed):`n%s`n`n', $otpauthUri);
    $output->output('Then enter your first one-time token to finish activation.`n');

    // Only verify after an actual token submission; avoid false errors on initial setup page load.
    $submittedToken = Http::post('token');
    if ($submittedToken !== null) {
        $token = trim((string) $submittedToken);
        if ($token !== '') {
            $window = (int) get_module_setting('window');
            $result = TwoFactorAuthService::verifyTotp($tempSecret, $token, $digits, $period, $window, 0);

            if ($result['valid']) {
                set_module_pref('enabled', 1);
                set_module_pref('secret_encrypted', (string) get_module_pref('temp_secret_encrypted'));
                set_module_pref('temp_secret_encrypted', '');
                set_module_pref('verified_at', time());
                set_module_pref('last_used_timestep', $result['timestep']);
                $output->output('Two-factor authentication is now enabled.`n');
            } else {
                $output->output('The token was invalid. Please try again.`n');
            }
        }
    }

    // Raw form actions are not auto-whitelisted by addnav(), so register them explicitly.
    addnav('', 'runmodule.php?module=twofactorauth&op=setup');
    rawoutput("<form action='runmodule.php?module=twofactorauth&op=setup' method='POST'>");
    rawoutput("<label>" . translate_inline('Authenticator token') . "</label> ");
    rawoutput("<input type='text' name='token' maxlength='10'> ");
    rawoutput("<button type='submit'>" . translate_inline('Enable') . "</button>");
    rawoutput('</form>');
}

/**
 * Render the 2FA challenge while login is pending.
 */
function twofactorauth_render_challenge(Output $output): void
{
    if ((int) get_module_pref('pending_challenge') !== 1) {
        $output->output('No two-factor challenge is currently pending.`n');

        return;
    }

    Nav::add('Actions');
    Nav::add('Submit token', 'runmodule.php?module=twofactorauth&op=verify');
    Nav::add('Disable via email', 'runmodule.php?module=twofactorauth&op=disable_email');

    $output->output('Password accepted, two-factor authentication is required.`n');
    $output->output('Enter the token from your authenticator app to continue.`n');

    // Raw form actions are not auto-whitelisted by addnav(), so register them explicitly.
    addnav('', 'runmodule.php?module=twofactorauth&op=verify');
    rawoutput("<form action='runmodule.php?module=twofactorauth&op=verify' method='POST'>");
    rawoutput("<label>" . translate_inline('Authenticator token') . "</label> ");
    rawoutput("<input type='text' name='token' maxlength='10'> ");
    rawoutput("<button type='submit'>" . translate_inline('Verify') . "</button>");
    rawoutput('</form>');
}

/**
 * Verify challenge tokens with lockout and replay protection.
 */
function twofactorauth_handle_challenge_verification(Output $output): void
{
    global $session;

    if ((int) get_module_pref('pending_challenge') !== 1) {
        Redirect::redirect('village.php', '2FA verify without pending state');
    }

    $now = time();
    $lockedUntil = (int) get_module_pref('locked_until');
    if ($lockedUntil > $now) {
        $output->output('Too many failures. Please wait before trying again.`n');

        return;
    }

    $secret = TwoFactorAuthService::decryptSecret((string) get_module_pref('secret_encrypted'), twofactorauth_signing_key());
    $token = (string) Http::post('token');
    $digits = (int) get_module_setting('token_digits');
    $period = (int) get_module_setting('period_seconds');
    $window = (int) get_module_setting('window');
    $lastStep = (int) get_module_pref('last_used_timestep');

    $result = TwoFactorAuthService::verifyTotp($secret, $token, $digits, $period, $window, $lastStep, $now);
    if ($result['valid']) {
        set_module_pref('last_used_timestep', $result['timestep']);
        twofactorauth_clear_pending_state();
        $session['user']['restorepage'] = '';
        $output->output('Two-factor authentication complete. Welcome back.`n');
        Nav::add('Continue', 'village.php');

        return;
    }

    $fails = (int) get_module_pref('failed_attempts') + 1;
    set_module_pref('failed_attempts', $fails);

    $maxAttempts = (int) get_module_setting('max_attempts');
    if ($fails >= $maxAttempts) {
        $lockSeconds = (int) get_module_setting('lock_seconds');
        set_module_pref('locked_until', $now + $lockSeconds);
        $output->output('Too many failures. Challenge temporarily locked.`n');
    } else {
        $output->output('Invalid token. Please try again.`n');
    }
}

/**
 * Send a signed, short-lived disable link to the account email.
 */
function twofactorauth_handle_disable_via_email(Output $output): void
{
    global $session;

    $acctId = (int) ($session['user']['acctid'] ?? 0);
    $email = (string) ($session['user']['emailaddress'] ?? '');
    if ($acctId < 1 || strpos($email, '@') === false) {
        $output->output('No valid email address is available for recovery.`n');

        return;
    }

    $expires = time() + ((int) get_module_setting('disable_link_ttl_minutes') * 60);
    $token = TwoFactorAuthService::signDisableToken($acctId, $email, $expires, twofactorauth_signing_key());
    $confirmUri = 'runmodule.php?module=twofactorauth&op=confirm_disable&token=' . rawurlencode($token);

    set_module_pref('disable_token_hash', hash('sha256', $token));
    set_module_pref('disable_token_expires', $expires);
    set_module_pref('disable_token_uri', $confirmUri);

    $subject = translate_inline('Two-factor disable request');
    $body = sprintf(
        "%s\n\n%s\n%s",
        translate_inline('A request was made to disable two-factor authentication for your account.'),
        translate_inline('Use this one-time link before it expires:'),
        $confirmUri
    );

    systemmail($acctId, $subject, $body);
    $output->output('A recovery link has been sent to your account email.`n');
}

/**
 * Consume and validate the disable token and force re-login.
 */
function twofactorauth_handle_disable_confirmation(Output $output): void
{
    global $session;

    $token = (string) Http::get('token');
    $validation = TwoFactorAuthService::verifyDisableToken($token, twofactorauth_signing_key());
    $expectedHash = (string) get_module_pref('disable_token_hash');
    $expires = (int) get_module_pref('disable_token_expires');

    $hashMatches = $expectedHash !== '' && hash_equals($expectedHash, hash('sha256', $token));
    if (!$validation['valid'] || !$hashMatches || $expires < time()) {
        $output->output('The recovery token is invalid or expired.`n');

        return;
    }

    set_module_pref('enabled', 0);
    set_module_pref('secret_encrypted', '');
    set_module_pref('temp_secret_encrypted', '');
    set_module_pref('verified_at', 0);
    set_module_pref('last_used_timestep', 0);
    set_module_pref('backup_codes_hash', '');
    set_module_pref('disable_token_hash', '');
    set_module_pref('disable_token_expires', 0);
    set_module_pref('disable_token_uri', '');

    twofactorauth_clear_pending_state();

    GameLog::log('2FA disabled via email recovery link', 'security', false, (int) $session['user']['acctid'], 'warning');

    $output->output('Two-factor authentication has been disabled. Please log in again with your password.`n');
    Nav::add('Log out now', 'login.php?op=logout');
}

/**
 * Clear current challenge flags after success, disable, or logout.
 */
function twofactorauth_clear_pending_state(): void
{
    set_module_pref('pending_challenge', 0);
    set_module_pref('pending_since', 0);
    set_module_pref('failed_attempts', 0);
    set_module_pref('locked_until', 0);
    set_module_pref('disable_token_uri', '');
}

/**
 * Return the shared signing/encryption key material for this module.
 */
function twofactorauth_signing_key(): string
{
    return hash('sha256', getsetting('serverurl', 'lotgd') . '|' . getsetting('gameadminemail', 'admin@example.com'));
}
