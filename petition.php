<?php

use Lotgd\SuAccess;
use Lotgd\Stripslashes;
use Lotgd\Nav\SuperuserNav;

// addnews ready
// translator ready
// mail ready
define("ALLOW_ANONYMOUS", true);
define("OVERRIDE_FORCED_NAV", true);
require_once("common.php");
require_once("lib/output_array.php");
require_once("lib/http.php");
$op = httpget('op');

switch ($op) {
    case "primer":
    case "faq":
    case "faq1":
    case "faq2":
    case "faq3":
                    require("pages/petition/petition_$op.php");
        break;
    default:
            require("pages/petition/petition_default.php");
        break;
}
popup_footer();
