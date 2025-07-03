<?php
namespace Lotgd;

use Lotgd\Output;

class Redirect
{
    /**
     * Perform an HTTP redirect while saving session state.
     *
     * @param string      $location Target location
     * @param string|bool $reason   Optional reason for redirect
     */
    public static function redirect(string $location, $reason = false): void
    {
        global $session, $REQUEST_URI;
        if (strpos($location, 'badnav.php') === false) {
            $session['allowednavs'] = [];
            addnav('', $location);
            $failoutput = new Output();
            $failoutput->output_notl("`lWhoops, your navigation is broken. Hopefully we can restore it.`n`n");
            $failoutput->output_notl('`$');
            $failoutput->rawoutput("<a href=\"" . HTMLEntities($location, ENT_COMPAT, getsetting('charset', 'ISO-8859-1')) . "\">" . translate_inline('Click here to continue.', 'badnav') . "</a>");
            $failoutput->output_notl(translate_inline("`n`n\$If you cannot leave this page, notify the staff via <a href='petition.php'>petition</a> `\$and tell them where this happened and what you did. Thanks.", 'badnav'), true);
            $text = $failoutput->get_output();
            $session['output'] = "<html><head><link href=\"templates/common/colors.css\" rel=\"stylesheet\" type=\"text/css\"></head><body style='background-color: #000000'>$text</body></html>";
        }
        Buffs::restoreBuffFields();
        if (!isset($session['debug'])) {
            $session['debug'] = '';
        }
        $session['debug'] .= "Redirected to $location from $REQUEST_URI.  $reason<br>";
        saveuser();
        $host = $_SERVER['HTTP_HOST'];
        $http = $_SERVER['SERVER_PORT'] == 443 ? 'https' : 'http';
        $uri = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
        header("Location: $http://$host$uri/$location");
        echo translate_inline('Whoops. There has been an error concering redirecting your to your new page. Please inform the admins about this. More Information for your petition down below:\n\n');
        echo $session['debug'];
        exit();
    }
}
