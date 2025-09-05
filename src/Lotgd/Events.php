<?php

declare(strict_types=1);

/**
 * Handle random special events that may occur in various locations.
 */

namespace Lotgd;

use Lotgd\DateTime;
use Lotgd\Http;
use Lotgd\Modules\HookHandler;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Translator;
use Lotgd\Util\ScriptName;

class Events
{
// This file encapsulates all the special event handling for most locations

// Returns whether or not the description should be skipped
    /**
     * Process any queued special event for the current player location.
     *
     * @param string      $location    Player location identifier
     * @param string|null $baseLink    Optional base link to return to
     * @param string|null $needHeader  Optional header for the event page
     *
     * @return bool Whether the location description should be skipped
     */
    public static function handleEvent(string $location, ?string $baseLink = null, ?string $needHeader = null): bool
    {
        if ($baseLink === null) {
                $baseLink = ScriptName::current() . '.php?';
        } else {
                //debug("Base link was specified as $baseLink");
                //debug(debug_backtrace());
        }
        global $session, $badguy;
        $output = Output::getInstance();
        $skipdesc = false;

        Translator::getInstance()->setSchema("events");
        $allowinactive = false;
        $eventhandler = Http::get('eventhandler');
        if (($session['user']['superuser'] & SU_DEVELOPER) && $eventhandler != "") {
            $allowinactive = true;
            $array = preg_split("/[:-]/", $eventhandler);
            if ($array[0] == "module") {
                $session['user']['specialinc'] = "module:" . $array[1];
            } else {
                $session['user']['specialinc'] = "";
            }
        }

        $_POST['i_am_a_hack'] = 'true';

        if ($session['user']['specialinc'] != "") {
            $specialinc = $session['user']['specialinc'];
            $session['user']['specialinc'] = "";
            if ($needHeader !== null) {
                    Header::pageHeader($needHeader);
            }

            $output->output("`^`c`bSomething Special!`c`b`0");
            if (strchr($specialinc, ":")) {
                $array = explode(":", $specialinc);
                $starttime = DateTime::getMicroTime();
                $hookname = '';
                $row = ['modulename' => $array[1]];
                HookHandler::doEvent($location, $array[1], $allowinactive, $baseLink);
                $endtime = DateTime::getMicroTime();
                if (($endtime - $starttime >= 1.00 && ($session['user']['superuser'] & SU_DEBUG_OUTPUT))) {
                    $output->debug("Slow Event (" . round($endtime - $starttime, 2) . "s): $hookname - {$row['modulename']}`n");
                }
            }
            if (Nav::checkNavs()) {
                // The page rendered some linkage, so we just want to exit.
                Footer::pageFooter();
            } else {
                $skipdesc = true;
                $session['user']['specialinc'] = "";
                $session['user']['specialmisc'] = "";
                Http::set("op", "");
            }
        }
        Translator::getInstance()->setSchema();
        return $skipdesc;
    }
}
