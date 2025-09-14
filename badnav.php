<?php

declare(strict_types=1);

use Lotgd\Translator;

/**
 * \file badnav.php
 * This file handles the badnavs that occurr and displays either the last pagehit or an empty page where the user can petition.
 * @see lib/redirect.php
 */

// translator ready
use Lotgd\Accounts;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav\VillageNav;
use Lotgd\Nav;
use Lotgd\DateTime;
use Lotgd\MySQL\Database;
use Lotgd\Redirect;

// addnews ready
// mail ready
define("OVERRIDE_FORCED_NAV", true);
require_once __DIR__ . "/common.php";

Translator::getInstance()->setSchema("badnav");

if ($session['user']['loggedin'] && $session['loggedin']) {
    if (isset($session['output']) && strpos($session['output'], "<!--CheckNewDay()-->")) {
        DateTime::checkDay();
    }
    foreach ($session['allowednavs'] as $key => $val) {
        //hack-tastic.
        $key = (string) $key;
        if (
            trim($key) == "" ||
            $key === 0 ||
            substr($key, 0, 8) == "motd.php" ||
            substr($key, 0, 8) == "mail.php"
        ) {
            unset($session['allowednavs'][$key]);
        }
    }
    $sql = "SELECT output FROM " . Database::prefix("accounts_output") . " WHERE acctid={$session['user']['acctid']};";
    $result = Database::query($sql);
    if (Database::numRows($result) < 1) {
        //no output found, nothing to set
        $row = array ("output" => '');
    } else {
        $row = Database::fetchAssoc($result);
        if ($row['output'] > "") {
            $row['output'] = gzuncompress($row['output']);
        }
        if (strpos("HTML", $row['output']) !== false && $row['output'] != '') {
            $row['output'] = gzuncompress($row['output']);
        }
        //check if the output needs to be unzipped again
        //and make sure '' is not within gzuncompress -> error
    }
    if (
        !is_array($session['allowednavs']) ||
            count($session['allowednavs']) == 0 || $row['output'] == ""
    ) {
        $session['allowednavs'] = array();
        Header::pageHeader("Your Navs Are Corrupted");
        if ($session['user']['alive']) {
            VillageNav::render();
            $output->output(
                "Your navs are corrupted, please return to %s.",
                $session['user']['location']
            );
        } else {
            Nav::add("Return to Shades", "shades.php");
            $output->output("Your navs are corrupted, please return to the Shades.");
        }
        Footer::pageFooter();
    }
    echo $row['output'];
    $session['debug'] = "";
    $session['user']['allowednavs'] = $session['allowednavs'];
    Accounts::saveUser();
} else {
    $session = array();
    Translator::translatorSetup();
    Redirect::redirect("index.php");
}
