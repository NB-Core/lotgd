<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Accounts;
use Lotgd\Output;
use Lotgd\Nav;
use Lotgd\Translator;
use Lotgd\PhpGenericEnvironment;

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
        header("Location: $http://$host$uri/$location");

    //fall through if this does not work!
        $session['debug'] .= "Redirected on '$host' with protocol '$http' to uri '$uri' to location '$location' from location '$requestUri'.<br/><br/>Reasion if given:<br/>  $reason<br>";
        $text = Translator::translateInline('Whoops. There has been an error concering redirecting your to your new page. Please inform the admins about this. More Information for your petition down below:<br/><br/>');
        echo "<html><head><link href=\"templates/common/colors.css\" rel=\"stylesheet\" type=\"text/css\"></head><body style='background-color: #000000; color: #fff;'>$text</body></html>";
        echo $session['debug'];
        exit();
    }
}
