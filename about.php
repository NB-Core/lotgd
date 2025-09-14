<?php

declare(strict_types=1);

use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready


/**
* \file about.php
* This file displays the 'About Lotgd' navigation and the appropriate Text including the license.
*
*
*
*/

define("ALLOW_ANONYMOUS", true);
require_once __DIR__ . "/common.php";

use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\DateTime;

Translator::getInstance()->setSchema("about");

Header::pageHeader("About Legend of the Green Dragon Core Engine");
$details = gametimedetails();

DateTime::checkDay();
$op = Http::get('op');

switch ($op) {
    case "setup":
    case "listmodules":
    case "license":
            require __DIR__ . "/pages/about/about_$op.php";
        break;
    default:
            require __DIR__ . "/pages/about/about_default.php";
        break;
}
if ($session['user']['loggedin']) {
    Nav::add("Return to the news", "news.php");
} else {
    Nav::add("Login Page", "index.php");
}
Footer::pageFooter();
