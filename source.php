<?php

use Lotgd\ErrorHandling;
use Lotgd\Http;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Settings;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready
define("ALLOW_ANONYMOUS", true);
define("OVERRIDE_FORCED_NAV", true);
require_once __DIR__ . "/common.php";
ErrorHandling::configure();

Translator::getInstance()->setSchema("source");
$output   = Output::getInstance();
$settings = Settings::getInstance();

$url = Http::get('url');
if ($url) {
    Header::popupHeader("Source code for %s", $url);
} else {
    Header::popupHeader("Source code");
}

if (
    $session['user']['loggedin'] &&
        $session['user']['superuser'] & SU_VIEW_SOURCE
) {
    $dir = str_replace("\\", "/", dirname($url) . "/");
    $scriptPath = $_SERVER['SCRIPT_NAME'] ?? '';
    $scriptBase = \Lotgd\Util\ScriptName::current();
    $pos = strrpos($scriptPath, $scriptBase);
    $scriptDir = $pos !== false ? substr($scriptPath, 0, $pos) : dirname($scriptPath);
    $subdir = str_replace("\\", "/", $scriptDir . "/");
    if ($subdir == "//") {
        $subdir = "/";
    }
    $legal_start_dirs = array(
            ($subdir == "//" ? "/" : $subdir) . "" => 1,
            ($subdir == "//" ? "/" : $subdir) . "lib/" => 1,
            ($subdir == "//" ? "/" : $subdir) . "modules/*" => 1,
    );
    $illegal_files = array(
            ($subdir == "//" ? "/" : $subdir) . "dbconnect.php" => "Contains sensitive information specific to this installation.",
            ($subdir == "//" ? "/" : $subdir) . "dragon.php" => "If you want to read the dragon script, I suggest you do so by defeating it!",
            ($subdir == "//" ? "/" : $subdir) . "output_translator.php" => "X", // hidden
            ($subdir == "//" ? "/" : $subdir) . "pavilion.php" => "Not released at least for now.",
            ($subdir == "//" ? "/" : $subdir) . "source.php" => "X", //hide completely -- so that people can't see the names of the other completely hidden files.
            ($subdir == "//" ? "/" : $subdir) . "remotebackup.php" => "X", // hide completely
            ($subdir == "//" ? "/" : $subdir) . "remotequery.php" => "X", // hide completely

            ($subdir == "//" ? "/" : $subdir) . "lib/datatable.php" => "X", // hide completely
            ($subdir == "//" ? "/" : $subdir) . "lib/dbremote.php" => "X", //hide completely
            ($subdir == "//" ? "/" : $subdir) . "config/smsnotify.php" => "X", //hide completely
            ($subdir == "//" ? "/" : $subdir) . "modules/battlearena.php" => "X", // not for dist
            ($subdir == "//" ? "/" : $subdir) . "modules/blog.php" => "X", // not for dist
            ($subdir == "//" ? "/" : $subdir) . "modules/clues.php" => "X", // hidden
            ($subdir == "//" ? "/" : $subdir) . "modules/lycanthropy.php" => "X", // hidden
            ($subdir == "//" ? "/" : $subdir) . "modules/mutagens.php" => "X", // hidden
            ($subdir == "//" ? "/" : $subdir) . "modules/privacy.php" => "X", // hidden
            ($subdir == "//" ? "/" : $subdir) . "modules/store.php" => "X", // not for dist
            ($subdir == "//" ? "/" : $subdir) . "modules/tournament.php" => "X", // hide
    );
    $legal_files = array();

    $output->rawOutput("<h1>");
    $output->output("View Source: ");
    $output->outputNotl("%s", htmlentities($url, ENT_COMPAT, $settings->getSetting("charset", "UTF-8")));
    $output->rawOutput("</h1>");
    $output->output("<a href='#source'>Click here for the source,</a> OR`n", true);
    $output->output("`bOther files that you may wish to view the source of:`b");
    $output->rawOutput("<ul>");
    // Gather all the legal dirs
    $legal_dirs = array();
    foreach ($legal_start_dirs as $dir => $value) {
        // If this is a dir to exclude, skip it
        if (!$value) {
            continue;
        }

        $sdir = substr($dir, strlen($subdir));
        if ($sdir == $scriptDir) {
            $sdir = "";
        }
        $base = "./$sdir";

        // If this is not a 'recursive' dir, add it and continue
        if (!strstr($base, "/*")) {
            array_push($legal_dirs, $base);
            continue;
        }

        // Strip of the /*
        $base = substr($base, 0, -2);
        array_push($legal_dirs, $base . "/");
        $d = dir("$base");

        while ($entry = $d->read()) {
            // Skip any . files
            if ($entry[0] == '.') {
                continue;
            }
            // skip any php files
            if (substr($entry, strrpos($entry, '.')) == ".php") {
                continue;
            }
            $ndir = $base . "/" . $entry;
            // Okay, check if it's a directory
            $test = preg_replace("!^\./!", "//", $ndir);
            if (is_dir($ndir)) {
                if (
                    !isset($legal_start_dirs[$test]) ||
                        $legal_start_dirs[$test] != 0
                ) {
                    array_push($legal_dirs, $ndir . "/");
                }
            }
        }
    }

    foreach ($legal_dirs as $key) {
        //$skey = substr($key,strlen($subdir));
        //if ($key==$scriptDir) $skey="";
        //$d = dir("./$skey");
        //if (substr($key,0,2)=="//") $key = substr($key,1);
        //if ($key=="//") $key="/";
        // Gaurentee a sort order on source files - Hidehisa Yasuda
        $key1 = substr($key, 2);
        $key2 = "/" . $key1;
        $skey = "//" . $key1;

        $d = dir("$key");
        $files = array();
        while (false !== ($entry = $d->read())) {
            if (substr($entry, strrpos($entry, ".")) == ".php") {
                array_push($files, "$entry");
            }
        }
        $d->close();
        asort($files);
        foreach ($files as $entry) {
            if (
                isset($illegal_files["$key2$entry"]) &&
                    $illegal_files["$key2$entry"] != ""
            ) {
                if ($illegal_files["$key2$entry"] == "X") {
                    //we're hiding the file completely.
                } else {
                    $output->rawOutput("<li>$key1$entry");
                    $reason = Translator::translateInline($illegal_files[$key2 . $entry]);
                    $output->output("&#151; This file cannot be viewed: %s", $reason, true);
                    $output->rawOutput("</li>\n");
                }
            } else {
                $output->rawOutput("<li><a href='source.php?url=$subdir$key1$entry'>$key1$entry</a> &#151; " . date("Y-m-d H:i:s", filemtime($key . "/" . $entry)) . "</li>\n");
                $legal_files["$subdir$key1$entry"] = true;
            }
        }
    }
    $output->rawOutput("</ul>");

    $output->rawOutput("<h1><a name='source'>");
    $output->output("Source of: %s", htmlentities($url, ENT_COMPAT, $settings->getSetting("charset", "UTF-8")));
    $output->rawOutput("</a></h1>");

    $page_name = substr($url, strlen($subdir) - 1);
    if (substr($page_name, 0, 1) == "/") {
        $page_name = substr($page_name, 1);
    }
    if ($legal_files[$url]) {
        $output->rawOutput("<table bgcolor=#cccccc>");
        $output->rawOutput("<tr><td>");
        $output->rawOutput("<font size=-1>");
        ob_start();
        show_source($page_name);
        $t = ob_get_contents();
        ob_end_clean();
        $output->rawOutput($t);
        $output->rawOutput("</font>", true);
        $output->rawOutput("</td></tr></table>", true);
    } elseif ($illegal_files[$url] != "" && $illegal_files[$url] != "X") {
        $reason = Translator::translateInline($illegal_files[$url]);
        $output->output("`nCannot view this file: %s`n", $reason);
    } else {
        $output->output("`nCannot view this file.`n");
        ;
    }
} else {
       $output->output("Due to the behaviour of people in the past, access to the source code online has been restricted.");
       $output->output("You may download the entirety of the latest publicly released stable version from <a href='http://www.dragonprime.net' target='_blank'>DragonPrime</a>.", true);
       $output->output("For the +NB version of Legend of the Green Dragon, visit our <a href='https://github.com/NB-Core/lotgd' target='_blank'>GitHub repository</a> where you can download releases or clone the project.", true);
       $output->output("You may then work with that code within the restrictions of its license.");
    $output->output("`n`nHopefully this will help put an end to actions like the following:");
    $output->rawOutput("<ul><li>");
    $output->output("Releasing code which they do not own without permission.");
    $output->rawOutput("</li><li>");
    $output->output("Removing valid copyright information from code and replacing it.");
    $output->rawOutput("</li><li>");
    $output->output("Removing portions of the code required to be kept intact by licensing.");
    $output->rawOutput("</li><li>");
    $output->output("Claiming copyright of items which they did not create.");
    $output->rawOutput("</li></ul>");
}
Footer::popupFooter();
