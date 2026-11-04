<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Accounts;
use Lotgd\Output;
use Lotgd\Nav;
use Lotgd\Translator;
use Lotgd\PhpGenericEnvironment;
use Lotgd\Page\PartialMode;

class Redirect
{
    /**
     * Perform an HTTP redirect while saving session state.
     *
     * @param string      $location Target location
     * @param string|bool $reason   Optional reason for redirect
     *
     * @return void
     */
    public static function redirect(string $location, string|bool $reason = false): void
    {
        if (self::isAsyncRequest()) {
            // A request the page sends in the background (async/process.php,
            // async/stream.php) is not a page change. Saving here would put
            // this one link in place of the links the page on screen shows,
            // and the player's next click would end on badnav.php -- a hook
            // on everyhit that redirects (a login challenge, a jail) did
            // exactly that. The page stays as it is; the caller gets JSON.
            self::refuseAsync(str_replace(["\r", "\n"], '', $location));
        }
        $session = &PhpGenericEnvironment::getSession();
        $requestUri = PhpGenericEnvironment::getRequestUri();
        $settings = Settings::getInstance();
        if (strpos($location, 'badnav.php') === false) {
            $session['allowednavs'] = [];
            Nav::add('', $location);
            $charset = $settings->getSetting('charset', 'UTF-8');
            $failoutput = new Output();
            $failoutput->outputNotl("`lWhoops, your navigation is broken. Hopefully we can restore it.`n`n");
            $failoutput->outputNotl('`$');
            $failoutput->rawoutput("<a href=\"" . HTMLEntities($location, ENT_COMPAT, $charset) . "\">" . Translator::translateInline('Click here to continue.', 'badnav') . "</a>");
            $failoutput->outputNotl(Translator::translateInline("`n`n`\$If you cannot leave this page, notify the staff via <a href='petition.php'>petition</a> `\$and tell them where this happened and what you did. Thanks.", 'badnav'), true);
            $text = $failoutput->getOutput();
            $session['output'] = "<html><head><link href=\"templates/common/colors.css\" rel=\"stylesheet\" type=\"text/css\"></head><body style='background-color: #000000'>$text</body></html>";
        }
        Buffs::restoreBuffFields();
        if (!isset($session['debug'])) {
            $session['debug'] = '';
        }

        Accounts::saveUser();
        $host = PhpGenericEnvironment::getServer('HTTP_HOST');
        $http = PhpGenericEnvironment::getServer('SERVER_PORT') == 443 ? 'https' : 'http';
        $uri = rtrim(dirname(PhpGenericEnvironment::getServer('PHP_SELF')), '/\\');
        // One header value for both ways out: no line break can end it early.
        $target = str_replace(["\r", "\n"], '', $location);
        if (PartialMode::isActive()) {
            // The client would follow a Location inside its request and swap
            // the target page into a region. A redirect is a real page change.
            PartialMode::sendHeader('HX-Redirect: ' . $target);
            exit();
        }
        header("Location: $http://$host$uri/$target");

    //fall through if this does not work!
        $session['debug'] .= "Redirected on '$host' with protocol '$http' to uri '$uri' to location '$location' from location '$requestUri'.<br/><br/>Reasion if given:<br/>  $reason<br>";
        $text = Translator::translateInline('Whoops. There has been an error concering redirecting your to your new page. Please inform the admins about this. More Information for your petition down below:<br/><br/>');
        echo "<html><head><link href=\"templates/common/colors.css\" rel=\"stylesheet\" type=\"text/css\"></head><body style='background-color: #000000; color: #fff;'>$text</body></html>";
        echo $session['debug'];
        exit();
    }

    /**
     * Whether this request is one of the async endpoints rather than a page.
     */
    private static function isAsyncRequest(): bool
    {
        return defined('AJAX_MODE') && AJAX_MODE;
    }

    /**
     * Answer an async request that would have been redirected, without saving.
     *
     * @param string $location Where a page would have gone
     */
    private static function refuseAsync(string $location): void
    {
        if (!headers_sent()) {
            http_response_code(409);
            header('Content-Type: application/json; charset=UTF-8');
            header('Cache-Control: no-store');
        }
        // A target that is not valid UTF-8 must still give JSON, never an empty body.
        $body = json_encode(
            ['status' => 'error', 'error' => 'redirect', 'location' => $location],
            JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES
        );
        echo $body !== false ? $body : '{"status":"error","error":"redirect"}';
        exit();
    }
}
