<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Stripslashes;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Http;
use Lotgd\Page\Footer;

// addnews ready
// translator ready
// mail ready
define("ALLOW_ANONYMOUS", true);
define("OVERRIDE_FORCED_NAV", true);
require_once __DIR__ . "/common.php";

$op = Http::get('op');

switch ($op) {
    case "primer":
    case "faq":
    case "faq1":
    case "faq2":
    case "faq3":
                    require __DIR__ . "/pages/petition/petition_$op.php";
        break;
    default:
            require __DIR__ . "/pages/petition/petition_default.php";
        break;
}
Footer::popupFooter();
