<?php

declare(strict_types=1);

require_once __DIR__ . '/TwoFactorAuth/TwoFactorAuthService.php';

use Lotgd\Http;
use Lotgd\Security\PasskeyCredentialRepository;
use Lotgd\Security\PasskeyService;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\GameLog;
use Lotgd\DebugLog;
use Lotgd\Redirect;
use Lotgd\Serialization;
use Lotgd\Translator;

/**
 * Two-step login module using TOTP challenges after password verification.
 */
function twofactorauth_getmoduleinfo(): array
{
    $overrideForcedNav = twofactorauth_should_override_forced_nav_for_setup_async();

    return [
        'name' => 'Two Factor Auth',
        'version' => '1.0.0',
        'author' => 'Oliver Brendei',
        'category' => 'Security',
        'download' => 'core_module',
        // Only bypass forced-nav for authenticated setup async routes that must emit raw JSON.
        // Leaving this broad would let normal module page flow skip nav enforcement.
        'override_forced_nav' => $overrideForcedNav,
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
            'passkeys_enabled' => 'Has at least one passkey enrolled,viewonly',
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
            'resume_restorepage' => 'Stored pre-challenge restore target,viewonly',
            'resume_allowednavs_json' => 'Stored pre-challenge allowed-nav map as JSON,viewonly',
        ],
    ];
}

/**
 * Restrict forced-nav bypass to passkey setup fetch endpoints.
 *
 * runmodule.php performs a second forced-nav check after common bootstrap. We only bypass
 * that check for setup async routes to prevent fetch requests from being treated as the user's
 * next interactive navigation target.
 */
function twofactorauth_should_override_forced_nav_for_setup_async(): bool
{
    global $session;

    if (!(($session['loggedin'] ?? false) === true) || (int) ($session['user']['acctid'] ?? 0) <= 0) {
        return false;
    }

    $op = (string) Http::get('op');
    $setupOp = (string) Http::get('setupop');
    if ($op !== 'setup') {
        return false;
    }

    if ($setupOp !== 'begin_passkey_registration' && $setupOp !== 'finish_passkey_registration') {
        return false;
    }

    $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? ''));

    return $requestMethod === 'POST';
}

function twofactorauth_install(): bool
{
    // Run late so our restorepage/challenge redirect wins over other modules mutating login destination.
    module_addhook_priority('player-login', 1000);
    module_addhook('player-logout');
    module_addhook('everyhit');
    module_addhook('footer-prefs');

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

            $totpEnabled = (int) get_module_pref('enabled') === 1;
            $acctId = (int) ($session['user']['acctid'] ?? 0);
            // Recompute passkey presence on login to avoid stale pref dead-ends.
            $passkeysEnabled = $acctId > 0 && count(twofactorauth_passkey_repository()->listForAccount($acctId)) > 0;
            set_module_pref('passkeys_enabled', $passkeysEnabled ? 1 : 0);
            if (!$totpEnabled && !$passkeysEnabled) {
                twofactorauth_clear_pending_state();
                break;
            }

            twofactorauth_stage_resume_snapshot_in_session();

            $session['twofactorauth_pending'] = true;
            break;

        case 'player-logout':
            twofactorauth_clear_pending_state();
            break;

        case 'footer-prefs':
            Nav::add('Security');
            Nav::add('Two-factor authentication', 'runmodule.php?module=twofactorauth&op=setup');
            break;

        case 'everyhit':
            if (!($session['loggedin'] ?? false)) {
                break;
            }

            if (($session['twofactorauth_pending'] ?? false) === true) {
                set_module_pref('pending_challenge', 1);
                set_module_pref('pending_since', time());
                set_module_pref('failed_attempts', 0);
                set_module_pref('locked_until', 0);

                twofactorauth_persist_staged_resume_snapshot();
                twofactorauth_clear_session_staging_keys();
                unset($session['twofactorauth_pending']);
            }

            if ((int) get_module_pref('pending_challenge') !== 1) {
                break;
            }

            $confirmUri = (string) get_module_pref('disable_token_uri');
            $allowed = TwoFactorAuthService::buildAllowedChallengeNavs($confirmUri !== '' ? $confirmUri : null);

            $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            if ($requestUri !== '' && str_starts_with($requestUri, '/')) {
                $requestUri = ltrim($requestUri, '/');
            }

            // Normalize to script+query so matching tolerates host/path differences.
            // Keep async transport requests allowlisted while the challenge is pending:
            // Jaxon polling/passkey methods expect JSON, and an HTML redirect response
            // causes the client parser to fail before challenge handlers can run.
            $uriPath = (string) parse_url($requestUri, PHP_URL_PATH);
            $uriQuery = (string) parse_url($requestUri, PHP_URL_QUERY);
            $normalizedRequestUri = ltrim($uriPath, '/') . ($uriQuery !== '' ? ('?' . $uriQuery) : '');

            if (!TwoFactorAuthService::isUriAllowed($normalizedRequestUri, $allowed)) {
                $challengeUrl = 'runmodule.php?module=twofactorauth&op=challenge';
                // Register redirect target right before redirect so core allowed-nav persistence keeps this route.
                Nav::add('', $challengeUrl);
                Redirect::redirect($challengeUrl, '2FA jail redirect');
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

    $acctId = (int) ($session['user']['acctid'] ?? 0);
    if ($acctId > 0) {
        DebugLog::add(
            sprintf('2FA run entry account %d op=%s.', $acctId, $op),
            $acctId,
            $acctId,
            '2fa_verify',
            false,
            false
        );
    }
    twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'entry', $acctId);

    // Keep setup async routes in the explicit nav allow-list for this request lifecycle.
    // Forced navigation checks compare the incoming URI against allowednavs and can reroute
    // requests before handlers run when async endpoints are not whitelisted.
    twofactorauth_allow_setup_async_nav_routes();
    twofactorauth_register_passkey_transition_nav_targets();

    if ($op === 'setup') {
        $setupOp = (string) Http::get('setupop');
        // Setup async handlers must short-circuit before page chrome/nav rendering so browser
        // fetch callers always receive raw JSON responses.
        if ($setupOp === 'begin_passkey_registration') {
            // CSRF validation is performed in the handler; run() records delegation boundaries.
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'pre-csrf', $acctId);
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'pre-service_call', $acctId);
            twofactorauth_handle_begin_passkey_registration();
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'post-service_call', $acctId);
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'post-csrf', $acctId);
            // Output is emitted in the async handler; keep explicit run() checkpoints for traceability.
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'pre-output', $acctId);
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'post-output', $acctId);
            Translator::tlschema();

            return;
        }

        if ($setupOp === 'finish_passkey_registration') {
            // CSRF validation is performed in the handler; run() records delegation boundaries.
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'pre-csrf', $acctId);
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'pre-service_call', $acctId);
            twofactorauth_handle_finish_passkey_registration();
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'post-service_call', $acctId);
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'post-csrf', $acctId);
            // Output is emitted in the async handler; keep explicit run() checkpoints for traceability.
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'pre-output', $acctId);
            twofactorauth_log_setup_async_checkpoint('twofactorauth_run', 'post-output', $acctId);
            Translator::tlschema();

            return;
        }

        // Passkey UI depends on Jaxon handlers; ensure async bootstrap is prepared
        // regardless of the user's global AJAX preference for this critical 2FA flow.
        twofactorauth_force_async_bootstrap();

        Header::pageHeader('Two-factor authentication setup');
        twofactorauth_render_setup($output);
        Footer::pageFooter();
        Translator::tlschema();

        return;
    }

    if (!($session['loggedin'] ?? false)) {
        Redirect::redirect('index.php?op=timeout', '2FA endpoint without login');
    }

    // JSON challenge operations must return raw JSON without page chrome wrappers.
    if ($op === 'begin_passkey_auth') {
        twofactorauth_handle_begin_passkey_auth();
        Translator::tlschema();

        return;
    }

    if ($op === 'verify_passkey') {
        twofactorauth_handle_passkey_verification();
        Translator::tlschema();

        return;
    }

    // Challenge passkey actions also need Jaxon available even when normal polling is disabled.
    twofactorauth_force_async_bootstrap();

    Header::pageHeader('Two-factor authentication challenge');

    Nav::add('Navigation');
    Nav::add('Logout', 'login.php?op=logout');

    if ($op === 'verify') {
        twofactorauth_handle_challenge_verification($output);
    } elseif ($op === 'resume') {
        twofactorauth_handle_resume($output);
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

    $acctId = (int) ($session['user']['acctid'] ?? 0);
    $enabled = (int) get_module_pref('enabled') === 1;
    $requireVerified = (int) get_module_setting('require_verified_email') === 1;
    $secret = (string) get_module_pref('secret_encrypted');
    $email = (string) ($session['user']['emailaddress'] ?? '');
    $csrf = twofactorauth_csrf_token();

    Nav::add('Navigation');
    Nav::add('Return to preferences', 'prefs.php');

    $output->output('`bTwo-factor authentication`b`n');

    if ($requireVerified && strpos($email, '@') === false) {
        $output->output('Your account needs a valid email address before this feature can be enabled.`n`n');

        return;
    }

    if ($enabled) {
        $output->output('Two-factor authentication is currently enabled on your account.`n');
        $output->output('`$If you lose access to your authenticator app, use the login challenge page to request email recovery.`0`n`n');
    }

    $repo = twofactorauth_passkey_repository();
    $passkeys = $repo->listForAccount($acctId);
    set_module_pref('passkeys_enabled', count($passkeys) > 0 ? 1 : 0);

    $setupOp = (string) Http::get('setupop');
    $deleteCredentialId = trim((string) Http::post('delete_credential_id'));
    if ($setupOp === 'delete_passkey' && $deleteCredentialId !== '') {
        $postedCsrf = (string) Http::post('csrf_token');
        if (hash_equals($csrf, $postedCsrf)) {
            if ($repo->deleteForAccount($acctId, $deleteCredentialId)) {
                $output->output('Passkey removed.`n');
            } else {
                $output->output('Passkey deletion failed or credential is not yours.`n');
            }
            $passkeys = $repo->listForAccount($acctId);
            set_module_pref('passkeys_enabled', count($passkeys) > 0 ? 1 : 0);
        } else {
            $output->output('Invalid request token.`n');
        }
    }

    $output->output('`n`bPasskeys`b`n');
    $output->output('Passkeys are available as an alternative second factor to TOTP during login.`n');

    addnav('', 'runmodule.php?module=twofactorauth&op=setup&setupop=begin_passkey_registration');
    addnav('', 'runmodule.php?module=twofactorauth&op=setup&setupop=finish_passkey_registration');
    rawoutput("<div id='twofactorauth-passkey-registration'>");
    rawoutput("<label>" . htmlspecialchars(translate_inline('Passkey label'), ENT_QUOTES, 'UTF-8') . "</label> ");
    rawoutput("<input type='text' id='passkey-label' value='This device' maxlength='120'> ");
    rawoutput("<button type='button' id='passkey-add-button'>" . htmlspecialchars(translate_inline('Add passkey'), ENT_QUOTES, 'UTF-8') . "</button>");
    rawoutput('</div>');

    if ($passkeys === []) {
        $output->output('No passkeys enrolled yet.`n');
    } else {
        foreach ($passkeys as $item) {
            $label = (string) ($item['label'] ?: 'Passkey');
            $credentialId = htmlspecialchars((string) $item['credential_id'], ENT_QUOTES, 'UTF-8');
            $created = (int) ($item['created_at'] ?? 0);
            $lastUsed = (int) ($item['last_used_at'] ?? 0);
            $output->output('• %s (created: %s, last used: %s)`n', $label, date('Y-m-d H:i', $created), $lastUsed > 0 ? date('Y-m-d H:i', $lastUsed) : 'never');
            addnav('', 'runmodule.php?module=twofactorauth&op=setup&setupop=delete_passkey');
            rawoutput("<form action='runmodule.php?module=twofactorauth&op=setup&setupop=delete_passkey' method='POST' style='display:inline-block;margin-bottom:6px'>");
            rawoutput("<input type='hidden' name='csrf_token' value='" . htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') . "'>");
            rawoutput("<input type='hidden' name='delete_credential_id' value='" . $credentialId . "'>");
            rawoutput("<button type='submit'>" . htmlspecialchars(translate_inline('Delete passkey'), ENT_QUOTES, 'UTF-8') . "</button>");
            rawoutput('</form><br>');
        }
    }

    $cryptoKey = twofactorauth_signing_key();

    if ($setupOp === 'start') {
        $secret = TwoFactorAuthService::generateSecret();
        set_module_pref('temp_secret_encrypted', TwoFactorAuthService::encryptSecret($secret, $cryptoKey));
    }

    $tempSecret = TwoFactorAuthService::decryptSecret((string) get_module_pref('temp_secret_encrypted'), $cryptoKey);
    if ($tempSecret === '') {
        Nav::add('Actions');
        if ($secret !== '') {
            $output->output("`nYou already have a TOTP device setup, you can remove it and setup a new device via email recovery`n`n");
        } else {
            $output->output('`nYou have not yet enrolled a TOTP device. Start setup to generate your TOTP secret.`n`n');
            Nav::add('Begin TOTP setup', 'runmodule.php?module=twofactorauth&op=setup&setupop=start');
        }

        twofactorauth_render_passkey_registration_script($csrf);

        return;
    }

    $issuer = (string) get_module_setting('issuer_name');
    $digits = (int) get_module_setting('token_digits');
    $period = (int) get_module_setting('period_seconds');
    $account = (string) ($session['user']['login'] ?? 'player');

    $otpauthUri = TwoFactorAuthService::buildOtpAuthUri($issuer, $account, $tempSecret, $digits, $period);
    $qrProviderEndpoint = trim((string) get_module_setting('qr_provider_endpoint'));
    $qrCodeSize = max(120, (int) get_module_setting('qr_code_size'));

    $output->output('`n`bTOTP fallback`b`n');
    $output->output('Scan the QR code in your authenticator app, or enter the secret manually.`n');
    $output->output('Manual secret: `^%s`0`n', $tempSecret);

    if ($qrProviderEndpoint !== '') {
        $qrCodeUrl = TwoFactorAuthService::buildQrCodeUrl($qrProviderEndpoint, $otpauthUri, $qrCodeSize);
        rawoutput("<div class='twofactorauth-qr'><img src='" . htmlspecialchars($qrCodeUrl, ENT_QUOTES, 'UTF-8') . "' alt='" . htmlspecialchars(translate_inline('Authenticator enrollment QR code'), ENT_QUOTES, 'UTF-8') . "' width='" . (int) $qrCodeSize . "' height='" . (int) $qrCodeSize . "'></div>");
    }

    $output->output('Enrollment URI (copy/paste if needed):`n%s`n`n', $otpauthUri);
    $output->output('Then enter your first one-time token to finish activation.`n');

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

    addnav('', 'runmodule.php?module=twofactorauth&op=setup');
    rawoutput("<form action='runmodule.php?module=twofactorauth&op=setup' method='POST'>");
    rawoutput("<label>" . translate_inline('Authenticator token') . "</label> ");
    rawoutput("<input type='text' name='token' maxlength='10'> ");
    rawoutput("<button type='submit'>" . translate_inline('Enable') . "</button>");
    rawoutput('</form>');

    twofactorauth_render_passkey_registration_script($csrf);
}

/**
 * Render the 2FA challenge while login is pending.
 */
function twofactorauth_render_challenge(Output $output): void
{
    global $session;

    if ((int) get_module_pref('pending_challenge') !== 1) {
        $output->output('No two-factor challenge is currently pending.`n');

        return;
    }

    $acctId = (int) ($session['user']['acctid'] ?? 0);
    $passkeys = twofactorauth_passkey_repository()->listForAccount($acctId);

    Nav::add('Actions');
    Nav::add('Submit token', 'runmodule.php?module=twofactorauth&op=verify');
    if ($passkeys !== []) {
        Nav::add('Use passkey', 'runmodule.php?module=twofactorauth&op=challenge');
        addnav('', 'runmodule.php?module=twofactorauth&op=begin_passkey_auth');
        addnav('', 'runmodule.php?module=twofactorauth&op=verify_passkey');
    }
    Nav::add('Disable via email', 'runmodule.php?module=twofactorauth&op=disable_email');

    $output->output('Password accepted, two-factor authentication is required.`n');
    $output->output('Enter the token from your authenticator app to continue.`n');

    addnav('', 'runmodule.php?module=twofactorauth&op=verify');
    // Keep explicit POST action; challenge verification must remain a classic form submit.
    rawoutput("<form id='twofactorauth-challenge-form' action='runmodule.php?module=twofactorauth&op=verify' method='POST'>");
    rawoutput("<label>" . translate_inline('Authenticator token') . "</label> ");
    rawoutput("<input type='text' name='token' maxlength='10'> ");
    rawoutput("<button type='submit'>" . translate_inline('Verify') . "</button>");
    rawoutput('</form>');

    if ($passkeys !== []) {
        // Shared helper utilities are needed both on setup and challenge pages.
        twofactorauth_render_passkey_js_helpers();
        twofactorauth_render_passkey_jaxon_bridge();
        $csrfJson = json_encode(twofactorauth_csrf_token()) ?: '""';
        $showDebugJson = twofactorauth_is_megauser() ? 'true' : 'false';
        rawoutput("<div style='margin-top:12px'><button type='button' id='twofactorauth-use-passkey'>" . htmlspecialchars(translate_inline('Use passkey'), ENT_QUOTES, 'UTF-8') . "</button></div>");
        rawoutput("<script>(function(){if(window.__lotgdTwofactorauthChallengeSetupDone){return;}window.__lotgdTwofactorauthChallengeSetupDone=true;const btn=document.getElementById('twofactorauth-use-passkey');if(!btn){return;}const csrfToken=" . $csrfJson . ";const showDebug=" . $showDebugJson . ";const resolvedHandlers=typeof window.getJaxonHandlers==='function'?window.getJaxonHandlers():null;const namespace=resolvedHandlers&&resolvedHandlers.TwoFactorAuthPasskey?resolvedHandlers.TwoFactorAuthPasskey:(window.Lotgd&&window.Lotgd.Async&&window.Lotgd.Async.Handler&&window.Lotgd.Async.Handler.TwoFactorAuthPasskey?window.Lotgd.Async.Handler.TwoFactorAuthPasskey:(window.JaxonLotgd&&window.JaxonLotgd.Async&&window.JaxonLotgd.Async.Handler&&window.JaxonLotgd.Async.Handler.TwoFactorAuthPasskey?window.JaxonLotgd.Async.Handler.TwoFactorAuthPasskey:null));const beginExists=!!(namespace&&typeof namespace.beginAuthentication==='function');if(showDebug){const diag=document.createElement('div');diag.style.marginTop='8px';diag.style.fontSize='12px';diag.style.color='#666';const namespaceName=namespace&&namespace.constructor&&namespace.constructor.name?String(namespace.constructor.name):'unknown';const requestUri=typeof window.twofactorauthGetCurrentJaxonRequestUri==='function'?window.twofactorauthGetCurrentJaxonRequestUri():'unavailable';diag.id='twofactorauth-passkey-client-uri-diag-challenge';diag.textContent='Passkey Jaxon diag: namespace='+namespaceName+' beginAuthentication='+String(beginExists)+' requestURI='+String(requestUri);btn.insertAdjacentElement('afterend',diag);}const buildDiag=function(data){if(!showDebug||!data){return'';}const code=data.error?String(data.error):'unknown';const diagId=data.diagnostic_id?String(data.diagnostic_id):'';const debug=data.debug_message?String(data.debug_message):'';const diagType=data.diagnostic&&data.diagnostic.type?String(data.diagnostic.type):'';let message='Passkey operation failed. Code: '+code+'.';if(diagId!==''){message+=' Diagnostic: '+diagId+'.';}if(diagType!==''){message+=' Type: '+diagType+'.';}if(debug!==''){message+=' Debug: '+debug;}return message;};btn.onclick=async function(){try{if(showDebug&&typeof window.twofactorauthGetCurrentJaxonRequestUri==='function'){const currentUri=window.twofactorauthGetCurrentJaxonRequestUri();console.debug('[TwoFactorAuthPasskey] beginAuthentication click requestURI:',currentUri);const uriDiag=document.getElementById('twofactorauth-passkey-client-uri-diag-challenge');if(uriDiag){uriDiag.textContent='Passkey Jaxon diag: beginAuthentication click requestURI='+String(currentUri);}}const beginData=await window.twofactorauthJaxonPasskeyCall('beginAuthentication',[csrfToken]);if(!beginData||!beginData.ok){alert(showDebug?buildDiag(beginData):'Passkey operation failed');return;}const publicKey=window.twofactorauthDecodeCredentialOptions(beginData.options.publicKey);const cred=await navigator.credentials.get({publicKey});if(!cred){alert('Passkey operation failed');return;}const body={id:cred.id,type:cred.type,response:{authenticatorData:window.twofactorauthArrayBufferToBase64Url(cred.response.authenticatorData),clientDataJSON:window.twofactorauthArrayBufferToBase64Url(cred.response.clientDataJSON),signature:window.twofactorauthArrayBufferToBase64Url(cred.response.signature),userHandle:cred.response.userHandle?window.twofactorauthArrayBufferToBase64Url(cred.response.userHandle):''}};const verifyData=await window.twofactorauthJaxonPasskeyCall('verifyAuthentication',[csrfToken,body]);if(verifyData&&verifyData.ok){window.location='runmodule.php?module=twofactorauth&op=resume';return;}alert(showDebug?buildDiag(verifyData):'Passkey operation failed');}catch(e){const detail=e&&e.message?String(e.message):'';alert(showDebug&&detail!==''?'Passkey operation failed. Diagnostic: '+detail:'Passkey operation failed');}};})();</script>");
    }
}

/**
 * Verify challenge tokens with lockout and replay protection.
 */
function twofactorauth_handle_challenge_verification(Output $output): void
{
    global $session;

    $acctId = (int) ($session['user']['acctid'] ?? 0);
    if ($acctId > 0) {
        DebugLog::add(sprintf('2FA verify handler entry account %d.', $acctId), $acctId, $acctId, '2fa_verify', false, false);
    }
    if ((int) get_module_pref('pending_challenge') !== 1) {
        Redirect::redirect('village.php', '2FA verify without pending state');
    }

    $rawToken = Http::post('token');
    if (!is_string($rawToken)) {
        $token = '';
    } else {
        $token = trim($rawToken);
    }
    $hasToken = $token !== '';
    $requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    // Temporary diagnostics: only persist verify request-entry details for valid accounts to
    // avoid noisy target=0 rows when unauthenticated/invalid requests hit the endpoint.
    if ($acctId > 0) {
        DebugLog::add(
            sprintf(
                '2FA verify entry account %d method=%s token_present=%s token_length=%d.',
                $acctId,
                $requestMethod,
                $hasToken ? 'yes' : 'no',
                strlen($token)
            ),
            $acctId,
            $acctId,
            '2fa_verify',
            false,
            false
        );
    }

    if ($requestMethod !== 'POST') {
        if ($acctId > 0) {
            DebugLog::add(
                sprintf(
                    '2FA verify request account %d used unexpected method=%s.',
                    $acctId,
                    $requestMethod
                ),
                $acctId,
                $acctId,
                '2fa_verify',
                false,
                false
            );
        }
        // Only classic form POST submissions are supported for verification; ignore other methods
        // without mutating lockout or failed-attempts state.
        $output->output('Invalid request method for verification.`n');

        return;
    }

    $now = time();
    $lockedUntil = (int) get_module_pref('locked_until');
    if ($lockedUntil > $now) {
        twofactorauth_log_challenge_outcome($acctId, 'failure', 'locked');
        if ($acctId > 0) {
            DebugLog::add(sprintf('2FA verify exit account %d branch=locked.', $acctId), $acctId, $acctId, '2fa_verify', false, false);
        }
        $output->output('Too many failures. Please wait before trying again.`n');

        return;
    }

    $secret = TwoFactorAuthService::decryptSecret((string) get_module_pref('secret_encrypted'), twofactorauth_signing_key());
    $digits = (int) get_module_setting('token_digits');
    $period = (int) get_module_setting('period_seconds');
    $window = (int) get_module_setting('window');
    $lastStep = (int) get_module_pref('last_used_timestep');

    $result = TwoFactorAuthService::verifyTotp($secret, $token, $digits, $period, $window, $lastStep, $now);
    if ($result['valid']) {
        set_module_pref('last_used_timestep', $result['timestep']);
        twofactorauth_clear_pending_state();
        twofactorauth_log_challenge_outcome($acctId, 'success');
        if ($acctId > 0) {
            DebugLog::add(sprintf('2FA verify exit account %d branch=valid timestep=%d.', $acctId, (int) $result['timestep']), $acctId, $acctId, '2fa_verify', false, false);
        }
        $output->output('Two-factor authentication complete. Welcome back.`n');
        Nav::add('Continue', 'runmodule.php?module=twofactorauth&op=resume');

        return;
    }

    $fails = (int) get_module_pref('failed_attempts') + 1;
    set_module_pref('failed_attempts', $fails);

    $maxAttempts = (int) get_module_setting('max_attempts');
    if ($fails >= $maxAttempts) {
        $lockSeconds = (int) get_module_setting('lock_seconds');
        set_module_pref('locked_until', $now + $lockSeconds);
        twofactorauth_log_challenge_outcome($acctId, 'failure', 'locked');
        if ($acctId > 0) {
            DebugLog::add(sprintf('2FA verify exit account %d branch=locked fails=%d.', $acctId, $fails), $acctId, $acctId, '2fa_verify', false, false);
        }
        $output->output('Too many failures. Challenge temporarily locked.`n');
    } else {
        // Slow down automated guessing while keeping the current challenge active for retries.
        sleep(2);
        twofactorauth_log_challenge_outcome($acctId, 'failure', (string) $result['reason']);
        if ($acctId > 0) {
            DebugLog::add(sprintf('2FA verify exit account %d branch=invalid reason=%s fails=%d.', $acctId, (string) ($result['reason'] ?? 'unknown'), $fails), $acctId, $acctId, '2fa_verify', false, false);
        }
        $output->output('Invalid token. Please try again.`n');
        twofactorauth_render_challenge($output);
    }
}

/**
 * Add debug-log audit events for 2FA challenge verification outcomes.
 */
function twofactorauth_log_challenge_outcome(int $acctId, string $event, ?string $reason = null): void
{
    if ($acctId < 1) {
        return;
    }

    $suffix = $reason !== null && $reason !== '' ? sprintf(' (reason: %s)', $reason) : '';
    DebugLog::add(
        sprintf('2FA token verification %s for account %d%s.', $event, $acctId, $suffix),
        $acctId,
        $acctId,
        // Keep this short: debuglog.field is varchar(20) in legacy schema.
        '2fa_verify',
        false,
        false
    );
}

/**
 * Restore pre-challenge navigation context and continue to the original target.
 */
function twofactorauth_handle_resume(Output $output): void
{
    global $session;

    if ((int) get_module_pref('pending_challenge') === 1) {
        Redirect::redirect('runmodule.php?module=twofactorauth&op=challenge', '2FA resume requested while challenge pending');
    }

    $storedTarget = trim((string) get_module_pref('resume_restorepage'));
    $storedAllowedNavs = twofactorauth_decode_allowednavs_snapshot((string) get_module_pref('resume_allowednavs_json'));

    if ($storedAllowedNavs !== []) {
        $session['allowednavs'] = $storedAllowedNavs;
    }

    if ($storedTarget !== '') {
        $session['user']['restorepage'] = $storedTarget;
    }

    $target = twofactorauth_resolve_resume_target($storedTarget, $storedAllowedNavs);

    twofactorauth_clear_resume_snapshot();
    twofactorauth_clear_session_staging_keys();

    if ($target === '') {
        $output->output('Two-factor authentication complete. Returning you to the village.`n');
        Redirect::redirect('village.php', '2FA resume fallback target');
    }

    Redirect::redirect($target, '2FA resume redirect to restorepage');
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
 * Stage the current login target and allowed-nav snapshot in session before everyhit persists prefs.
 */
function twofactorauth_stage_resume_snapshot_in_session(): void
{
    global $session;

    $session['twofactorauth_resume_restorepage'] = (string) ($session['user']['restorepage'] ?? '');

    // Prefer the in-memory allowlist, but fall back to account-serialized allowednavs
    // when login flow has not hydrated $session['allowednavs'] yet.
    $session['twofactorauth_resume_allowednavs'] = twofactorauth_collect_resume_allowednavs_snapshot();
}


/**
 * Collect the best-available allowed-nav snapshot for post-challenge resume.
 *
 * Priority order:
 * 1) Active session allowlist (already hydrated in this request)
 * 2) Account-stored serialized allowlist (pre-hydration login flows)
 *
 * @return array<string, bool>
 */
function twofactorauth_collect_resume_allowednavs_snapshot(): array
{
    global $session;

    $sessionAllowed = twofactorauth_snapshot_allowednavs($session['allowednavs'] ?? []);
    if ($sessionAllowed !== []) {
        return twofactorauth_ensure_nav_snapshot_has_passkey_transitions($sessionAllowed);
    }

    $serializedAccountAllowed = $session['user']['allowednavs'] ?? '';
    $decodedAccountAllowed = Serialization::safeUnserialize($serializedAccountAllowed);

    return twofactorauth_ensure_nav_snapshot_has_passkey_transitions(twofactorauth_snapshot_allowednavs($decodedAccountAllowed));
}

/**
 * Persist staged resume context into module prefs once everyhit confirms the pending challenge.
 */
function twofactorauth_persist_staged_resume_snapshot(): void
{
    global $session;

    $restorepage = (string) ($session['twofactorauth_resume_restorepage'] ?? '');
    $allowedNavs = twofactorauth_ensure_nav_snapshot_has_passkey_transitions(
        twofactorauth_snapshot_allowednavs($session['twofactorauth_resume_allowednavs'] ?? [])
    );

    set_module_pref('resume_restorepage', $restorepage);
    set_module_pref('resume_allowednavs_json', json_encode($allowedNavs, JSON_UNESCAPED_SLASHES) ?: '[]');
}

/**
 * Remove transient session staging keys once they are no longer needed.
 */
function twofactorauth_clear_session_staging_keys(): void
{
    global $session;

    unset($session['twofactorauth_resume_restorepage'], $session['twofactorauth_resume_allowednavs']);
}

/**
 * Clear stored resume snapshots after resume/abort/logout transitions.
 */
function twofactorauth_clear_resume_snapshot(): void
{
    set_module_pref('resume_restorepage', '');
    set_module_pref('resume_allowednavs_json', '');
}

/**
 * @param mixed $allowedNavs
 *
 * @return array<string, bool>
 */
function twofactorauth_snapshot_allowednavs(mixed $allowedNavs): array
{
    if (!is_array($allowedNavs)) {
        return [];
    }

    $snapshot = [];
    foreach ($allowedNavs as $uri => $isAllowed) {
        if (!is_string($uri) || $uri === '') {
            continue;
        }
        $snapshot[$uri] = (bool) $isAllowed;
    }

    return $snapshot;
}

/**
 * @return array<string, bool>
 */
function twofactorauth_decode_allowednavs_snapshot(string $json): array
{
    if ($json === '') {
        return [];
    }

    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    return twofactorauth_snapshot_allowednavs($decoded);
}

/**
 * Choose a safe post-challenge destination from the stored target and snapshot allowlist.
 */
function twofactorauth_resolve_resume_target(string $target, array $allowedNavs): string
{
    $target = trim($target);
    if ($target === '') {
        return '';
    }

    if (str_starts_with($target, '/')) {
        $target = ltrim($target, '/');
    }

    if (TwoFactorAuthService::isUriAllowed($target, array_keys($allowedNavs))) {
        return $target;
    }

    return '';
}


/**
 * Build or return a CSRF token for setup lifecycle actions.
 */
function twofactorauth_csrf_token(): string
{
    global $session;

    if (!isset($session['twofactorauth_csrf']) || !is_string($session['twofactorauth_csrf']) || $session['twofactorauth_csrf'] === '') {
        $session['twofactorauth_csrf'] = bin2hex(random_bytes(16));
    }

    return (string) $session['twofactorauth_csrf'];
}

/**
 * Shared passkey credential repository instance.
 */
function twofactorauth_passkey_repository(): PasskeyCredentialRepository
{
    static $repository = null;
    if (!$repository instanceof PasskeyCredentialRepository) {
        $repository = new PasskeyCredentialRepository();
    }

    return $repository;
}

/**
 * Shared passkey service instance.
 */
function twofactorauth_passkey_service(): PasskeyService
{
    static $service = null;
    if (!$service instanceof PasskeyService) {
        $service = new PasskeyService(twofactorauth_passkey_repository());
    }

    return $service;
}

/**
 * Render browser helpers for base64url decoding and passkey registration.
 */
function twofactorauth_render_passkey_js_helpers(): void
{
    rawoutput("<script>window.twofactorauthArrayBufferToBase64Url=window.twofactorauthArrayBufferToBase64Url||function(buffer){const bytes=new Uint8Array(buffer);let binary='';for(let i=0;i<bytes.length;i++){binary+=String.fromCharCode(bytes[i]);}return btoa(binary).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');};window.twofactorauthBase64UrlToArrayBuffer=window.twofactorauthBase64UrlToArrayBuffer||function(base64url){const padded=(base64url+'==='.slice((base64url.length+3)%4)).replace(/-/g,'+').replace(/_/g,'/');const binary=atob(padded);const bytes=new Uint8Array(binary.length);for(let i=0;i<binary.length;i++){bytes[i]=binary.charCodeAt(i);}return bytes.buffer;};window.twofactorauthDecodeCredentialOptions=window.twofactorauthDecodeCredentialOptions||function(publicKey){if(publicKey.challenge){publicKey.challenge=window.twofactorauthBase64UrlToArrayBuffer(publicKey.challenge);}if(publicKey.user&&publicKey.user.id){publicKey.user.id=window.twofactorauthBase64UrlToArrayBuffer(publicKey.user.id);}if(Array.isArray(publicKey.excludeCredentials)){publicKey.excludeCredentials=publicKey.excludeCredentials.map(function(c){if(c.id){c.id=window.twofactorauthBase64UrlToArrayBuffer(c.id);}return c;});}if(Array.isArray(publicKey.allowCredentials)){publicKey.allowCredentials=publicKey.allowCredentials.map(function(c){if(c.id){c.id=window.twofactorauthBase64UrlToArrayBuffer(c.id);}return c;});}return publicKey;};</script>");
}

/**
 * Render a small Jaxon bridge for promise-based passkey calls.
 *
 * Jaxon executes response commands asynchronously but does not return ceremony payloads
 * as fetch()-style JSON promises by default. This bridge maps request IDs to promise
 * resolvers and receives payloads from the async handler callback.
 *
 * Transport/parser failures can abort before callback execution; in that case we reject
 * pending promises immediately so users see the real failure instead of an artificial timeout.
 *
 * Preflight note: before invoking any passkey handler we verify the live Jaxon request URI still
 * points at /async/process.php. This catches endpoint drift immediately, instead of waiting for
 * bridge timeouts after requests are routed to non-JSON endpoints.
 */
function twofactorauth_render_passkey_jaxon_bridge(): void
{
    $showDebugJson = twofactorauth_is_megauser() ? 'true' : 'false';

    $script = <<<'JAVASCRIPT'
<script>(function(){window.twofactorauthPendingRequests=window.twofactorauthPendingRequests||{};const showDebug=__SHOW_DEBUG__;const expectedRequestPath='/async/process.php';const emitDebug=function(message,details){if(!showDebug){return;}const resolvedDetails=typeof details==='function'?details():details;const suffix=resolvedDetails?' Details: '+resolvedDetails:'';console.debug('[TwoFactorAuthPasskey] '+message+suffix);};const resolveCurrentRequestUri=function(){if(!window.jaxon||!jaxon.config){return'';}const configured=typeof jaxon.config.requestURI==='string'?jaxon.config.requestURI:'';if(configured===''){return'';}try{return new URL(configured,window.location.origin).pathname;}catch(_error){return configured;}};const appendClientUriDiagnostic=function(prefix){if(!showDebug){return;}const current=resolveCurrentRequestUri();emitDebug(prefix,function(){return'requestURI='+String(current||'unset');});};const rejectPending=function(requestId,message,details){if(!requestId){return;}const entry=window.twofactorauthPendingRequests[requestId];if(!entry){return;}if(entry.timeoutId){window.clearTimeout(entry.timeoutId);}delete window.twofactorauthPendingRequests[requestId];const composed=showDebug&&details?message+' Details: '+details:message;entry.reject(new Error(composed));};window.twofactorauthRejectAllPending=window.twofactorauthRejectAllPending||function(message,details){const ids=Object.keys(window.twofactorauthPendingRequests||{});for(let i=0;i<ids.length;i++){rejectPending(ids[i],message,details);}emitDebug('Rejected all pending bridge requests',details||'none');return ids.length;};window.twofactorauthHandleJaxonResponse=window.twofactorauthHandleJaxonResponse||function(requestId,payload){if(!requestId){return;}emitDebug('Callback received',function(){return'id='+String(requestId);});const entry=window.twofactorauthPendingRequests[requestId];if(!entry){emitDebug('Pending entry missing for callback',function(){return'id='+String(requestId);});return;}emitDebug('Pending entry found for callback',function(){return'id='+String(requestId);});if(entry.timeoutId){window.clearTimeout(entry.timeoutId);}delete window.twofactorauthPendingRequests[requestId];entry.resolve(payload||{ok:false,error:'empty_response'});};window.twofactorauthGetCurrentJaxonRequestUri=window.twofactorauthGetCurrentJaxonRequestUri||resolveCurrentRequestUri;window.twofactorauthPreflightPasskeyRequestUri=window.twofactorauthPreflightPasskeyRequestUri||function(method){const current=resolveCurrentRequestUri();if(current===expectedRequestPath){return{ok:true,current:current};}const detail='method='+String(method)+' requestURI='+String(current||'unset')+' expected='+expectedRequestPath;appendClientUriDiagnostic('Preflight rejected async call');return{ok:false,error:'request_uri_mismatch',detail:detail,current:current,expected:expectedRequestPath};};window.twofactorauthJaxonPasskeyCall=window.twofactorauthJaxonPasskeyCall||function(method,args){return new Promise(function(resolve,reject){const preflight=window.twofactorauthPreflightPasskeyRequestUri(method);if(!preflight.ok){const message='Passkey async endpoint mismatch.';const detail=preflight.detail||'';reject(new Error(showDebug&&detail!==''?message+' '+detail:message));return;}const resolvedHandlers=typeof window.getJaxonHandlers==='function'?window.getJaxonHandlers():null;const namespace=resolvedHandlers&&resolvedHandlers.TwoFactorAuthPasskey?resolvedHandlers.TwoFactorAuthPasskey:(window.Lotgd&&window.Lotgd.Async&&window.Lotgd.Async.Handler&&window.Lotgd.Async.Handler.TwoFactorAuthPasskey?window.Lotgd.Async.Handler.TwoFactorAuthPasskey:(window.JaxonLotgd&&window.JaxonLotgd.Async&&window.JaxonLotgd.Async.Handler&&window.JaxonLotgd.Async.Handler.TwoFactorAuthPasskey?window.JaxonLotgd.Async.Handler.TwoFactorAuthPasskey:null));if(!namespace||typeof namespace[method]!=='function'){reject(new Error('Passkey async handler unavailable.'));return;}const requestId='tfa_'+Date.now()+'_'+Math.random().toString(16).slice(2);const timeoutId=window.setTimeout(function(){rejectPending(requestId,'Passkey async request timed out.','method='+String(method));},20000);window.twofactorauthPendingRequests[requestId]={resolve:resolve,reject:reject,timeoutId:timeoutId,method:method};emitDebug('Request created',function(){return'id='+String(requestId);});const params=Array.isArray(args)?args.slice():[];params.unshift(requestId);try{namespace[method].apply(namespace,params);appendClientUriDiagnostic('Passkey async dispatched');}catch(error){rejectPending(requestId,'Passkey async request dispatch failed.',error&&error.message?String(error.message):'unknown');}});};if(!window.__lotgdTwofactorPasskeyBridgeRejectionHooks){window.__lotgdTwofactorPasskeyBridgeRejectionHooks=true;window.addEventListener('unhandledrejection',function(event){const reason=event&&event.reason&&event.reason.message?String(event.reason.message):'';if(reason.indexOf('Unexpected end of JSON input')!==-1||reason.indexOf('JSON')!==-1){window.twofactorauthRejectAllPending('Passkey async parser failure.',reason);}});window.addEventListener('error',function(event){const message=event&&event.message?String(event.message):'';if(message.indexOf('Unexpected end of JSON input')!==-1){window.twofactorauthRejectAllPending('Passkey async parser failure.',message);}});}})();</script>
JAVASCRIPT;

    rawoutput(str_replace('__SHOW_DEBUG__', $showDebugJson, $script));
}

/**
 * Force-load async/Jaxon setup for passkey pages.
 *
 * Footer normally includes async/setup.php only when user AJAX preference is enabled.
 * Passkey 2FA must continue to work regardless of that preference, so this helper loads
 * the async bootstrap explicitly before headers are rendered on setup/challenge pages.
 *
 * Contract note: async/setup.php appends markup with string concatenation into a
 * $pre_headscript buffer that it expects to receive from the including scope. This must
 * be initialized as a string so Jaxon handler namespaces are bootstrapped reliably for
 * passkey setup/login flows.
 */
function twofactorauth_force_async_bootstrap(): void
{
    static $bootstrapInjected = false;
    if ($bootstrapInjected) {
        return;
    }

    $asyncSetupFile = dirname(__DIR__) . '/async/setup.php';
    if (!file_exists($asyncSetupFile)) {
        return;
    }

    // Ensure async/setup.php sees the expected globals and capture any head scripts it registers.
    global $session;

    /** @var string|array<int, string> $pre_headscript */
    // Keep string contract for async/setup.php and defensively normalize legacy array buffers.
    $pre_headscript = '';

    require_once $asyncSetupFile;

    // async/setup.php expects string concatenation. Normalize array fallbacks into one buffer,
    // then add the resulting head markup exactly once in this module request lifecycle.
    if (is_array($pre_headscript)) {
        $pre_headscript = implode('', array_filter($pre_headscript, static fn(mixed $item): bool => is_string($item) && $item !== ''));
    } elseif (!is_string($pre_headscript)) {
        $pre_headscript = '';
    }

    if ($pre_headscript !== '') {
        Output::addHeadMarkup($pre_headscript);
        $bootstrapInjected = true;
    }
}

/**
 * Build an async challenge error payload and expose debug internals only to megausers.
 *
 * @return array<string, mixed>
 */
function twofactorauth_challenge_async_error_payload(string $errorCode, ?\Throwable $exception = null): array
{
    $payload = ['ok' => false, 'error' => $errorCode, 'code' => $errorCode];
    if ($exception instanceof \Throwable && twofactorauth_is_megauser()) {
        $payload['debug_message'] = sprintf('%s: %s', $exception::class, $exception->getMessage());
    }

    return $payload;
}

/**
 * Determine whether deep client/server diagnostics may be shown.
 */
function twofactorauth_is_megauser(): bool
{
    global $session;

    $superuserFlags = (int) ($session['user']['superuser'] ?? 0);

    return ($superuserFlags & SU_MEGAUSER) === SU_MEGAUSER;
}

/**
 * Render browser helpers for base64url decoding and passkey registration.
 */
function twofactorauth_render_passkey_registration_script(string $csrf): void
{
    twofactorauth_render_passkey_js_helpers();
    twofactorauth_render_passkey_jaxon_bridge();

    $csrfJson = json_encode($csrf) ?: '""';
    $showDebugJson = twofactorauth_is_megauser() ? 'true' : 'false';

    // Assign via `onclick` so repeated script injection cannot stack multiple handlers.
    // This setup page can be re-rendered in some module flows.
    rawoutput("<script>(function(){const button=document.getElementById('passkey-add-button');if(!button){return;}const csrfToken=" . $csrfJson . ";const showDebug=" . $showDebugJson . ";if(showDebug){const uriDiag=document.createElement('div');uriDiag.id='twofactorauth-passkey-client-uri-diag-setup';uriDiag.style.marginTop='8px';uriDiag.style.fontSize='12px';uriDiag.style.color='#666';const initialUri=typeof window.twofactorauthGetCurrentJaxonRequestUri==='function'?window.twofactorauthGetCurrentJaxonRequestUri():'unavailable';uriDiag.textContent='Passkey Jaxon diag: beginRegistration requestURI='+String(initialUri);button.insertAdjacentElement('afterend',uriDiag);}const buildDiag=function(data){if(!showDebug||!data){return'';}const code=data.error?String(data.error):'unknown';const diagId=data.diagnostic_id?String(data.diagnostic_id):'';const debug=data.debug_message?String(data.debug_message):'';const diagType=data.diagnostic&&data.diagnostic.type?String(data.diagnostic.type):'';let message='Passkey operation failed. Code: '+code+'.';if(diagId!==''){message+=' Diagnostic: '+diagId+'.';}if(diagType!==''){message+=' Type: '+diagType+'.';}if(debug!==''){message+=' Debug: '+debug;}return message;};button.onclick=async function(){try{const labelEl=document.getElementById('passkey-label');const label=labelEl?labelEl.value:'';if(showDebug&&typeof window.twofactorauthGetCurrentJaxonRequestUri==='function'){const currentUri=window.twofactorauthGetCurrentJaxonRequestUri();console.debug('[TwoFactorAuthPasskey] beginRegistration click requestURI:',currentUri);const uriDiag=document.getElementById('twofactorauth-passkey-client-uri-diag-setup');if(uriDiag){uriDiag.textContent='Passkey Jaxon diag: beginRegistration click requestURI='+String(currentUri);}}const beginData=await window.twofactorauthJaxonPasskeyCall('beginRegistration',[csrfToken,label]);if(!beginData||!beginData.ok){alert(showDebug?buildDiag(beginData):'Passkey operation failed');return;}const publicKey=window.twofactorauthDecodeCredentialOptions(beginData.options.publicKey);const credential=await navigator.credentials.create({publicKey});if(!credential){alert('Passkey operation failed');return;}const payload={id:credential.id,type:credential.type,response:{attestationObject:window.twofactorauthArrayBufferToBase64Url(credential.response.attestationObject),clientDataJSON:window.twofactorauthArrayBufferToBase64Url(credential.response.clientDataJSON),transports:typeof credential.response.getTransports==='function'?credential.response.getTransports():[]}};const finishData=await window.twofactorauthJaxonPasskeyCall('finishRegistration',[csrfToken,label,payload]);if(finishData&&finishData.ok){window.location='runmodule.php?module=twofactorauth&op=setup';return;}alert(showDebug?buildDiag(finishData):'Passkey operation failed');}catch(error){const detail=error&&error.message?String(error.message):'';alert(showDebug&&detail!==''?'Passkey operation failed. Diagnostic: '+detail:'Passkey operation failed');}};})();</script>");
}

/**
 * Emit a JSON payload for passkey async endpoints.
 *
 * @param array<string, mixed> $payload
 */
function twofactorauth_output_json(array $payload): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }

    rawoutput(json_encode($payload) ?: '{"ok":false}');
}

/**
 * Keep setup async routes explicitly in allowed navigation entries.
 *
 * Forced-nav can redirect requests before module code fully executes. These endpoints are called
 * by JavaScript fetch() during setup and must stay forced-nav safe so JSON responses are not
 * replaced by HTML redirects/chrome. Keep this allow-list narrow to avoid global bypasses.
 */
function twofactorauth_allow_setup_async_nav_routes(): void
{
    $op = (string) Http::get('op');
    $setupOp = (string) Http::get('setupop');

    if ($op !== 'setup') {
        return;
    }

    if ($setupOp !== 'begin_passkey_registration' && $setupOp !== 'finish_passkey_registration') {
        return;
    }

    addnav('', 'runmodule.php?module=twofactorauth&op=setup&setupop=' . $setupOp);
}

/**
 * Return passkey lifecycle routes that must remain in allowed-nav snapshots.
 *
 * Async passkey success paths redirect the browser back to runmodule URLs. If these
 * routes are missing from the forced-nav allowlist snapshot, the player can be sent to
 * badnav.php after a successful ceremony.
 *
 * @return array<int, string>
 */
function twofactorauth_passkey_transition_nav_targets(): array
{
    return [
        'runmodule.php?module=twofactorauth&op=challenge',
        'runmodule.php?module=twofactorauth&op=resume',
        'runmodule.php?module=twofactorauth&op=setup',
    ];
}

/**
 * Register passkey transition routes in the active request allowlist.
 *
 * These addnav() entries protect challenge/setup/resume redirects triggered by
 * passkey success/failure handlers and by browser-side async completion callbacks.
 */
function twofactorauth_register_passkey_transition_nav_targets(): void
{
    foreach (twofactorauth_passkey_transition_nav_targets() as $target) {
        addnav('', $target);
    }
}

/**
 * Ensure stored allowed-nav snapshots retain passkey transition URLs.
 *
 * Persisting these entries prevents forced-nav mismatches when async completion lands on
 * op=resume or op=setup after the challenge lifecycle has already persisted its snapshot.
 *
 * @param array<string, bool> $allowedNavs
 *
 * @return array<string, bool>
 */
function twofactorauth_ensure_nav_snapshot_has_passkey_transitions(array $allowedNavs): array
{
    foreach (twofactorauth_passkey_transition_nav_targets() as $target) {
        $allowedNavs[$target] = true;
    }

    return $allowedNavs;
}

/**
 * Return a shared correlation id for this module request.
 *
 * Async passkey setup calls run via fetch() and never render full page chrome, so we attach
 * one request-scoped id to every checkpoint log line to make end-to-end tracing reliable.
 */
function twofactorauth_setup_async_correlation_id(): string
{
    static $correlationId = null;
    if (!is_string($correlationId)) {
        $correlationId = bin2hex(random_bytes(8));
    }

    return $correlationId;
}

/**
 * Write structured debug checkpoint details for passkey setup async handlers.
 */
function twofactorauth_log_setup_async_checkpoint(string $handler, string $checkpoint, int $acctId): void
{
    DebugLog::add(
        sprintf(
            '2FA passkey setup async [%s] checkpoint=%s corr=%s acct=%d.',
            $handler,
            $checkpoint,
            twofactorauth_setup_async_correlation_id(),
            $acctId
        ),
        $acctId,
        $acctId,
        '2fa_passkey',
        false,
        false
    );
}

/**
 * Build a setup async error payload while limiting detailed diagnostic data to megausers.
 *
 * @return array<string, mixed>
 */
function twofactorauth_setup_async_error_payload(string $errorCode, ?\Throwable $exception = null): array
{
    // Privilege-gate deep diagnostics: only megausers get internals to avoid leaking
    // exception context to normal players while still enabling admin troubleshooting.
    $payload = ['ok' => false, 'error' => $errorCode, 'code' => $errorCode];
    if ($exception instanceof \Throwable && twofactorauth_is_megauser()) {
        $payload['debug_message'] = sprintf('%s: %s', $exception::class, $exception->getMessage());
    }

    return $payload;
}

/**
 * Begin setup passkey registration and return publicKeyCredentialCreationOptions as JSON.
 *
 * Output contract note: this endpoint is called via fetch() and must always emit JSON on every
 * exit path. Returning HTML or an empty body breaks frontend parsing and hides actionable errors.
 */
function twofactorauth_handle_begin_passkey_registration(): void
{
    global $session;

    $acctId = (int) ($session['user']['acctid'] ?? 0);
    twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'entry', $acctId);

    try {
        $requestBody = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($requestBody)) {
            $requestBody = [];
        }

        twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'pre-csrf', $acctId);
        $csrf = (string) ($requestBody['csrf_token'] ?? '');
        if (!hash_equals(twofactorauth_csrf_token(), $csrf)) {
            twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'post-csrf', $acctId);
            twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'pre-output', $acctId);
            twofactorauth_output_json(twofactorauth_setup_async_error_payload('csrf'));
            twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'post-output', $acctId);

            return;
        }
        twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'post-csrf', $acctId);

        $login = (string) ($session['user']['login'] ?? 'player');
        $display = (string) ($session['user']['name'] ?? $login);
        $existing = twofactorauth_passkey_repository()->listForAccount($acctId);
        $excludeIds = array_map(static fn(array $item): string => (string) ($item['credential_id'] ?? ''), $existing);

        twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'pre-service_call', $acctId);
        $options = twofactorauth_passkey_service()->beginRegistration($acctId, $login, $display, $excludeIds);
        twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'post-service_call', $acctId);

        twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'pre-output', $acctId);
        twofactorauth_output_json(['ok' => true, 'options' => $options]);
        twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'post-output', $acctId);

        return;
    } catch (\Throwable $e) {
        DebugLog::add(
            sprintf(
                '2FA passkey registration begin exception for account %d corr=%s (%s: %s).',
                $acctId,
                twofactorauth_setup_async_correlation_id(),
                $e::class,
                $e->getMessage()
            ),
            $acctId,
            $acctId,
            '2fa_passkey',
            false,
            false
        );

        twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'pre-output', $acctId);
        twofactorauth_output_json(twofactorauth_setup_async_error_payload('begin_exception', $e));
        twofactorauth_log_setup_async_checkpoint('begin_passkey_registration', 'post-output', $acctId);

        return;
    }
}

/**
 * Complete setup passkey registration and persist credential metadata.
 *
 * Output contract note: this endpoint is called via fetch() and must always emit JSON on every
 * exit path. Returning HTML or an empty body breaks frontend parsing and hides actionable errors.
 */
function twofactorauth_handle_finish_passkey_registration(): void
{
    global $session;

    $acctId = (int) ($session['user']['acctid'] ?? 0);
    twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'entry', $acctId);

    try {
        $requestBody = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($requestBody)) {
            $requestBody = [];
        }

        twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'pre-csrf', $acctId);
        $csrf = (string) ($requestBody['csrf_token'] ?? '');
        if (!hash_equals(twofactorauth_csrf_token(), $csrf)) {
            twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'post-csrf', $acctId);
            twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'pre-output', $acctId);
            twofactorauth_output_json(twofactorauth_setup_async_error_payload('csrf'));
            twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'post-output', $acctId);

            return;
        }
        twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'post-csrf', $acctId);

        $label = (string) ($requestBody['label'] ?? 'Passkey');

        twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'pre-service_call', $acctId);
        $result = twofactorauth_passkey_service()->finishRegistration($acctId, $requestBody, $label);
        twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'post-service_call', $acctId);

        if ($result['ok']) {
            DebugLog::add(sprintf('2FA passkey registration success for account %d.', $acctId), $acctId, $acctId, '2fa_passkey', false, false);
        } else {
            DebugLog::add(sprintf('2FA passkey registration failure for account %d (reason: %s).', $acctId, $result['error']), $acctId, $acctId, '2fa_passkey', false, false);
        }

        twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'pre-output', $acctId);
        twofactorauth_output_json(['ok' => $result['ok'], 'error' => $result['error'], 'code' => $result['ok'] ? '' : (string) $result['error']]);
        twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'post-output', $acctId);

        return;
    } catch (\Throwable $e) {
        DebugLog::add(
            sprintf(
                '2FA passkey registration finish exception for account %d corr=%s (%s: %s).',
                $acctId,
                twofactorauth_setup_async_correlation_id(),
                $e::class,
                $e->getMessage()
            ),
            $acctId,
            $acctId,
            '2fa_passkey',
            false,
            false
        );

        twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'pre-output', $acctId);
        twofactorauth_output_json(twofactorauth_setup_async_error_payload('finish_exception', $e));
        twofactorauth_log_setup_async_checkpoint('finish_passkey_registration', 'post-output', $acctId);

        return;
    }
}

/**
 * Begin passkey authentication challenge for the pending 2FA login.
 */
function twofactorauth_handle_begin_passkey_auth(): void
{
    global $session;

    // Ensure there is a pending 2FA challenge and the account is not locked out
    $twofaState        = $session['user']['twofactorauth'] ?? [];
    $pendingChallenge  = (int) ($twofaState['pending_challenge'] ?? 0);
    $lockedUntil       = isset($twofaState['locked_until']) ? (int) $twofaState['locked_until'] : 0;
    $now               = time();

    if ($pendingChallenge !== 1) {
        twofactorauth_output_json([
            'ok'    => false,
            'error' => 'no_pending_2fa_challenge',
        ]);
        return;
    }

    if ($lockedUntil > 0 && $lockedUntil > $now) {
        twofactorauth_output_json([
            'ok'           => false,
            'error'        => 'locked_out',
            'locked_until' => $lockedUntil,
        ]);
        return;
    }

    try {
        $acctId = (int) ($session['user']['acctid'] ?? 0);
        $existing = twofactorauth_passkey_repository()->listForAccount($acctId);
        $credentialIds = array_map(static fn(array $item): string => (string) ($item['credential_id'] ?? ''), $existing);

        $options = twofactorauth_passkey_service()->beginAuthentication($acctId, $credentialIds);

        twofactorauth_output_json(['ok' => true, 'options' => $options]);
    } catch (\Throwable $exception) {
        $acctId = (int) ($session['user']['acctid'] ?? 0);
        DebugLog::add(
            sprintf('2FA passkey challenge begin exception for account %d (%s: %s).', $acctId, $exception::class, $exception->getMessage()),
            $acctId,
            $acctId,
            '2fa_passkey',
            false,
            false
        );

        twofactorauth_output_json(twofactorauth_challenge_async_error_payload('begin_auth_exception', $exception));
    }
}

/**
 * Complete passkey authentication and clear pending 2FA state on success.
 */
function twofactorauth_handle_passkey_verification(): void
{
    global $session;

    try {
        if ((int) get_module_pref('pending_challenge') !== 1) {
            twofactorauth_output_json(['ok' => false, 'error' => 'no_pending']);

            return;
        }

        $acctId = (int) ($session['user']['acctid'] ?? 0);
        $lockedUntil = (int) get_module_pref('locked_until');
        $now = time();
        if ($lockedUntil > $now) {
            twofactorauth_output_json(['ok' => false, 'error' => 'locked']);

            return;
        }

        $requestBody = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (!is_array($requestBody)) {
            $requestBody = [];
        }

        $result = twofactorauth_passkey_service()->finishAuthentication($acctId, $requestBody);
        if ($result['ok']) {
            twofactorauth_clear_pending_state();
            twofactorauth_log_challenge_outcome($acctId, 'success', 'passkey');
            DebugLog::add(sprintf('2FA passkey authentication success for account %d.', $acctId), $acctId, $acctId, '2fa_passkey', false, false);
            twofactorauth_output_json(['ok' => true]);

            return;
        }

        $fails = (int) get_module_pref('failed_attempts') + 1;
        set_module_pref('failed_attempts', $fails);
        $maxAttempts = (int) get_module_setting('max_attempts');
        if ($fails >= $maxAttempts) {
            set_module_pref('locked_until', $now + (int) get_module_setting('lock_seconds'));
        }

        twofactorauth_log_challenge_outcome($acctId, 'failure', 'passkey_' . $result['error']);
        DebugLog::add(sprintf('2FA passkey authentication failure for account %d (reason: %s).', $acctId, $result['error']), $acctId, $acctId, '2fa_passkey', false, false);
        twofactorauth_output_json(['ok' => false, 'error' => $result['error']]);
    } catch (\Throwable $exception) {
        $acctId = (int) ($session['user']['acctid'] ?? 0);
        DebugLog::add(
            sprintf('2FA passkey challenge verify exception for account %d (%s: %s).', $acctId, $exception::class, $exception->getMessage()),
            $acctId,
            $acctId,
            '2fa_passkey',
            false,
            false
        );

        twofactorauth_output_json(twofactorauth_challenge_async_error_payload('verify_auth_exception', $exception));
    }
}

/**
 * Return the shared signing/encryption key material for this module.
 */
function twofactorauth_signing_key(): string
{
    return hash('sha256', getsetting('serverurl', 'lotgd') . '|' . getsetting('gameadminemail', 'admin@example.com'));
}
