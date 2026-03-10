<?php

declare(strict_types=1);

/**
 * reCAPTCHA v3 module integration (Legend of the Green Dragon).
 *
 * README (short)
 * --------------
 * This module adds Google reCAPTCHA v3 protection to account creation and
 * petition submission via NB-Core hooks. It renders a hidden token field and
 * uses the v3 "action" to tie tokens to the originating form. On the server,
 * it verifies the token with Google's siteverify endpoint, enforcing:
 * - success = true
 * - score >= configured minimum
 * - action matches the expected action for the hook
 *
 * Why it's implemented this way:
 * - reCAPTCHA v3 is a score-based system; there is no user challenge, so we
 *   must rely on server-side verification plus a configurable score threshold.
 * - The "action" parameter helps prevent token replay across different forms.
 * - The module uses CURL with timeouts to avoid hanging requests and performs
 *   best-effort verification; if CURL or the verification service is unavailable
 *   or misconfigured, verification may be skipped and normal processing continues.
 *
 * Setup:
 * 1) Create a reCAPTCHA v3 site in the Google Cloud Console.
 * 2) Copy your Site Key and Secret Key into this module's settings.
 * 3) Tune the minimum score based on your traffic.
 *
 * Docs:
 * https://cloud.google.com/recaptcha/docs/overview
 */

/**
 * Provide module metadata and settings for the reCAPTCHA integration.
 *
 * @return array Module information and configuration definitions.
 */
function recaptcha_getmoduleinfo(): array
{
    $info = array(
            "name" => "Google ReCaptcha Plugin",
            "version" => "1.0",
            "author" => "`2Oliver Brendel",
            "override_forced_nav" => true,
            "category" => "Administrative",
            "download" => "",
            "settings" => array(
                "Captcha Settings,title",
                "sitekey" => "Your Google Site Key,text|KEY",
                "sitesecret" => "Your Google Site Secret,text|SECRET",
                "min_score" => "Minimum acceptable score,range,0,1,0.1|0.5",
                ),
             );
    return $info;
}

/**
 * Register module hooks and ensure dependencies are available.
 *
 * @return bool True when installed successfully; false when missing CURL.
 */
function recaptcha_install(): bool
{
    if (extension_loaded('curl')) {
        debug("CURL is necessary to make this work and is loaded.`n");
    } else {
        debug("CURL PHP5 extension is necessary and NOT loaded! Install it on your server!`n");
        return false;
    }
    module_addhook_priority("addpetition", 50);
    module_addhook_priority("check-create", 50);
    module_addhook_priority("pre-login", 50);
    module_addhook_priority("create-form", 50);
    module_addhook_priority("index-login", 50);
    module_addhook_priority("petitionform", 50);
    return true;
}

/**
 * Map module hooks to reCAPTCHA v3 actions.
 *
 * Keeping this mapping in one place avoids drift between render-time action
 * assignment and verify-time enforcement.
 *
 * @return array<string, string> Hook name to expected action mapping.
 */
function recaptcha_get_hook_action_map(): array
{
    return array(
        'create-form' => 'create',
        'check-create' => 'create',
        'petitionform' => 'petition',
        'addpetition' => 'petition',
        'index-login' => 'login',
        'pre-login' => 'login',
    );
}

/**
 * Resolve the expected reCAPTCHA action for a given hook.
 *
 * @param string $hookname Current NB-Core hook name.
 *
 * @return string|null Action name when mapped; null when unsupported.
 */
function recaptcha_get_expected_action(string $hookname): ?string
{
    $actionMap = recaptcha_get_hook_action_map();

    return $actionMap[$hookname] ?? null;
}

/**
 * Uninstall hook for the module.
 *
 * @return bool Always true; no teardown required.
 */
function recaptcha_uninstall(): bool
{
    return true;
}

/**
 * Hook handler to render reCAPTCHA widgets and verify submissions.
 *
 * @param string $hookname Hook name provided by the engine.
 * @param array  $args     Hook arguments.
 *
 * @return array Modified hook arguments.
 */
function recaptcha_dohook(string $hookname, array $args): array
{
    global $session;
    if (!extension_loaded('curl')) {
        // Without CURL, we cannot reach Google's verification endpoint safely.
        output("Verification by Captcha disabled. Code #154 Order 66`n");
        return $args;
    }
    // Load configured reCAPTCHA keys and score threshold from module settings.
    $sitekey = (string) get_module_setting('sitekey');
    $sitesecret = (string) get_module_setting('sitesecret');
    $minScore = (float) get_module_setting('min_score');
    $expectedAction = recaptcha_get_expected_action($hookname);

    $verificationHooks = array('check-create', 'addpetition', 'pre-login');
    $renderHooks = array('create-form', 'petitionform', 'index-login');

    switch ($hookname) {
        case "check-create":
        case "addpetition":
        case "pre-login":
            //verify captcha
            if ($sitesecret === '') {
                // Missing secret means we cannot verify server-side.
                // We fail closed for submissions, but the home page remains publicly viewable.
                $failureMessage = "`c`b`\$Sorry, the captcha service is unavailable. Please try again later.`b`c`n`n";
                recaptcha_apply_failure($hookname, $args, $failureMessage);
                break;
            }
            // Extract the v3 token posted by the client.
            $recaptchaResponse = (string) httppost('g-recaptcha-response');
            if ($recaptchaResponse === '') {
                // No token: treat as verification failure.
                $failureMessage = "`c`b`\$Sorry, but you entered the wrong captcha code, try again`b`c`n`n";
                recaptcha_apply_failure($hookname, $args, $failureMessage);
                break;
            }
            // Google reCAPTCHA siteverify endpoint (v3 compatible).
            $url = "https://www.google.com/recaptcha/api/siteverify";

            // Build POST payload: secret key and user token.
            $data = array(
                    'secret' => $sitesecret,
                    'response' => $recaptchaResponse
                     ); //parameters to be sent
            // Use CURL with timeouts to prevent blocking on network issues.
            $request = curl_init($url);
            curl_setopt($request, CURLOPT_POST, true);
            curl_setopt($request, CURLOPT_POSTFIELDS, http_build_query($data));
            curl_setopt($request, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($request, CURLOPT_TIMEOUT, 5);
            curl_setopt($request, CURLOPT_CONNECTTIMEOUT, 3);
            $verify = curl_exec($request);
            $curlError = curl_error($request);
            curl_close($request);

            if ($verify === false) {
                // Verification service unavailable or request failed.
                $failureMessage = sprintf("`c`b`\$Sorry, the captcha service is unavailable. Please try again later.`b`c(%s)`n`n", $curlError !== '' ? $curlError : 'not-available');
                recaptcha_apply_failure($hookname, $args, $failureMessage);
                break;
            }

            // Decode Google's response into an associative array.
            $captcha_success = json_decode($verify, true);
            // Basic success flag (boolean).
            $success = is_array($captcha_success) && (($captcha_success['success'] ?? false) === true);
            // Collect any error codes for debugging (non-secret).
            $errorCodes = array();
            if (is_array($captcha_success) && isset($captcha_success['error-codes']) && is_array($captcha_success['error-codes'])) {
                $errorCodes = $captcha_success['error-codes'];
            }
            // Extract v3 fields: score and action (may be absent on failure).
            $score = is_array($captcha_success) && isset($captcha_success['score']) ? (float) $captcha_success['score'] : null;
            $action = is_array($captcha_success) && isset($captcha_success['action']) ? (string) $captcha_success['action'] : null;

            if (
                $expectedAction === null
                || !in_array($hookname, $verificationHooks, true)
                || !$success
                || $score === null
                || $score < $minScore
                || $action !== $expectedAction
            ) {
                // Fail closed if the score is too low or the action is wrong.
                $extra = array();
                if ($score !== null) {
                    $extra[] = sprintf('score: %.2f', $score);
                }
                if ($action !== null) {
                    $extra[] = sprintf('action: %s', $action);
                }
                if (!empty($errorCodes)) {
                    $extra[] = implode(",", $errorCodes);
                }
                $extraMessage = !empty($extra) ? sprintf("(%s)", implode(" | ", $extra)) : '';
                $failureMessage = sprintf("`c`b`\$Sorry, but you entered the wrong captcha code, try again`b`c%s`n`n", $extraMessage);
                recaptcha_apply_failure($hookname, $args, $failureMessage);
            }
            // Remove the raw token from args; it's no longer needed.
            unset($args['g-recaptcha-response']); //unset this as it is useless now
            break;
        case "create-form":
        case "petitionform":
        case "index-login":
            if ($expectedAction === null || !in_array($hookname, $renderHooks, true) || $sitekey === '') {
                // Keep home/index rendering resilient for anonymous users even when
                // reCAPTCHA is misconfigured; submit-time hooks remain fail-closed.
                break;
            }

            // Login intentionally uses the exact same enterprise rendering/token path
            // as petition/create so all hooks share one code path and one token shape.
            recaptcha_render_enterprise_token(
                (string) $sitekey,
                $expectedAction,
                $hookname === 'index-login'
            );
            break;
    }
    return $args;
}

/**
 * Render enterprise.js and generate a reCAPTCHA token for the active form.
 *
 * All render hooks reuse this helper so login, create, and petition follow the
 * same enterprise token-generation flow and remain verification-compatible.
 *
 * @param string $sitekey         Public site key used by enterprise.js.
 * @param string $action          Expected reCAPTCHA action for this hook.
 * @param bool   $refreshOnSubmit True to refresh token at submit-time.
 *
 * @return void
 */
function recaptcha_render_enterprise_token(string $sitekey, string $action, bool $refreshOnSubmit): void
{
    $sitekeyJson = json_encode($sitekey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $actionJson = json_encode($action, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    $sitekeyQuery = rawurlencode($sitekey);
    $refreshOnSubmitJs = $refreshOnSubmit ? 'true' : 'false';

    rawoutput("<script src=\"https://www.google.com/recaptcha/enterprise.js?render={$sitekeyQuery}\" defer></script>");
    rawoutput("<input type=\"hidden\" name=\"g-recaptcha-response\" id=\"g-recaptcha-response\">");
    rawoutput("<script>
        document.addEventListener('DOMContentLoaded', function () {
            var siteKey = {$sitekeyJson};
            var action = {$actionJson};
            var refreshOnSubmit = {$refreshOnSubmitJs};
            var tokenInput = document.getElementById('g-recaptcha-response');
            var tokenPromise = null;
            var handledSubmit = false;

            // When the hook renders outside a <form> (e.g. index-login in
            // home.php), the hidden input has no parent form.  Relocate it
            // into the target form so the token is included in the POST data.
            if (tokenInput && !tokenInput.form) {
                var targetForm = document.querySelector(\"form[action*='login']\");
                if (targetForm) {
                    targetForm.appendChild(tokenInput);
                }
            }

            function executeEnterprise(forceRefresh) {
                if (!tokenInput || !window.grecaptcha || !grecaptcha.enterprise) {
                    // Without enterprise.js we cannot generate a token; server stays fail-closed.
                    return Promise.resolve('');
                }

                if (!forceRefresh && tokenPromise) {
                    return tokenPromise;
                }

                tokenPromise = new Promise(function (resolve) {
                    grecaptcha.enterprise.ready(function () {
                        grecaptcha.enterprise.execute(siteKey, { action: action }).then(function (token) {
                            tokenInput.value = token || '';
                            resolve(tokenInput.value);
                        }, function () {
                            tokenInput.value = '';
                            resolve('');
                        });
                    });
                });

                return tokenPromise;
            }

            executeEnterprise(true);

            if (refreshOnSubmit) {
                var form = tokenInput ? tokenInput.form : null;
                if (form) {
                    form.addEventListener('submit', function (event) {
                        if (handledSubmit) {
                            return;
                        }
                        event.preventDefault();
                        // Login submits can occur long after page load; refresh at submit-time
                        // to avoid stale/empty tokens that Google rejects for action=login.
                        executeEnterprise(true).then(function () {
                            handledSubmit = true;
                            form.submit();
                        });
                    });
                }
            }
        });
    </script>");
}

/**
 * Apply hook-specific failure behavior while keeping existing non-login logic.
 *
 * Account creation and petitions preserve historical cancel/block flags. Login
 * failures instead attach a message and clear the in-progress user session so
 * authentication cannot succeed on invalid, missing, or mismatched tokens.
 *
 * @param string $hookname Hook currently being processed.
 * @param array  $args     Hook argument payload, mutated by reference.
 * @param string $message  User-facing failure message.
 *
 * @return void
 */
function recaptcha_apply_failure(string $hookname, array &$args, string $message): void
{
    global $session;

    if ($hookname === 'pre-login') {
        // login.php renders only $session['message'] after the pre-login hook.
        // For this path we must not rely on hook args like cancelreason/msg.
        $loginMessage = recaptcha_normalize_login_failure_message($message);
        if (!isset($session['message']) || trim((string) $session['message']) === '') {
            // login.php reads this channel after HookHandler::hook("pre-login").
            // Always seed a non-empty fallback so captcha failures stay visible.
            $session['message'] = $loginMessage;
        } else {
            $session['message'] .= $loginMessage;
        }
        // Preserve core login behavior: append message, clear user, redirect to entry.
        $session['user'] = array();
        require_once('lib/redirect.php');
        redirect('index.php');
        return;
    }

    $args['cancelreason'] = $message;
    $args['cancelpetition'] = true;
    $args['blockaccount'] = true;
    $args['msg'] = $args['cancelreason'];
}

/**
 * Normalize a login failure message so it is always safe and non-empty.
 *
 * The login page displays $session['message'], therefore pre-login failures
 * must provide a direct user-facing string in that channel.
 *
 * @param string $message Candidate login failure message.
 *
 * @return string Sanitized, non-empty display message.
 */
function recaptcha_normalize_login_failure_message(string $message): string
{
    $normalizedMessage = trim(strip_tags($message));
    if ($normalizedMessage === '') {
        $normalizedMessage = 'Sorry, but you entered the wrong captcha code, try again.';
    }

    if (!str_ends_with($normalizedMessage, "\n")) {
        $normalizedMessage .= "\n\n";
    }

    return $normalizedMessage;
}

function recaptcha_run(): void
{
}
