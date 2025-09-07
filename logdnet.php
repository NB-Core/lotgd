<?php

declare(strict_types=1);

use Lotgd\MySQL\Database;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready

define("ALLOW_ANONYMOUS", true);
if (!isset($_GET['op']) || $_GET['op'] != 'list') {
    //don't want people to be able to visit the list while logged in -- breaks their navs.
    define("OVERRIDE_FORCED_NAV", true);
}
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\ErrorHandler;
use Lotgd\Backtrace;

require_once __DIR__ . "/common.php";
require_once __DIR__ . "/lib/sanitize.php";

Translator::getInstance()->setSchema("logdnet");

function lotgdsort($a, $b)
{
    // $a and $b are table rows.

    global $logd_version;
    $official_prefixes = array(
        "1.1.1 Dragonprime Edition",
        "1.1.0 Dragonprime Edition",
        "1.0.6",
        "1.0.5",
        "1.0.4",
        "1.0.3",
        "1.0.2",
        "1.0.1",
        "1.0.0",
        // MUST REMEMBER TO PUT NEW PRE-RELEASES HERE
        "0.9.7"
    );

    $aver = strtolower(str_replace(' ', '', $a['version']));
    $bver = strtolower(str_replace(' ', '', $b['version']));

    // Okay, if $a and $b are the same version, use the priority
    // This is true whether or not they are the official version or not.
    // We bubble the official version to the top below.
    if (strcmp($aver, $bver) == 0) {
        if ($a['priority'] == $b['priority']) {
            return 0;
        }
        return (($a['priority'] < $b['priority']) ? 1 : -1);
    }

    // Unknown versions are always worse than non-unknown
    if (strcmp($aver, "unknown") == 0 && strcmp($bver, "unknown") != 0) {
        return 1;
    }
    if (strcmp($bver, "unknown") == 0 && strcmp($aver, "unknown") != 0) {
        return -1;
    }

    // Check if either of them are a prefix.
    $costa = 10000;
    $costb = 10000;
    foreach ($official_prefixes as $index => $value) {
        if (strncmp($aver, $value, strlen($value)) == 0) {
            if ($costa == 10000) {
                $costa = $index;
            }
        }
        if (strncmp($bver, $value, strlen($value)) == 0) {
            if ($costb == 10000) {
                $costb = $index;
            }
        }
    }

    // If both are the same prefix (or no prefix), just strcmp.
    if ($costa == $costb) {
        return strcmp($aver, $bver);
    }

    return (($costa < $costb) ? -1 : 1);
}

$op = httpget('op');
if ($op == "") {
       $addy  = httpget('addy');
       $desc  = httpget('desc');
       $vers  = httpget('version');
       $admin = httpget('admin');
       $count = (int)httpget('c');
       $lang  = httpget('l');

    if ($vers == "") {
        $vers = "Unknown";
    }
    if ($admin == "" || $admin == "postmaster@localhost.com") {
        $admin = "unknown";
    }

    // See if we know this server.
    $sql = "SELECT lastupdate,serverid,lastping,recentips FROM " . Database::prefix("logdnet") . " WHERE address='" . Database::escape($addy) . "'";
    $result = Database::query($sql);
    $row = Database::fetchAssoc($result);

    // Clean up the desc
    $desc = logdnet_sanitize($desc);
    $desc = soap($desc);
    // Limit descs to 75 characters.
    if (strlen($desc) > 75) {
        $desc = substr($desc, 0, 75);
    }

    $date = date("Y-m-d H:i:s");
    if (Database::numRows($result) > 0) {
        // This is an already known server.

        // Eric, this below code does NOT work and causes a server to NEVER
        // get updated.. I'm commenting it out until you rethink it!
        // the server addy doesn't *change* so by checking this we never
        // update
        // It seems as if you thought this was the IP of the user logging in.
        // Also, nothing ever expires the IP from this list.
        //$ips = array_flip(explode(",",$row['recentips']));
        //if (isset($ips[$_SERVER['REMOTE_ADDR']])){
        //  //we've seen this user too recently.
        //}else{
        //  $ips = array_keys($ips);
        //  if (!isset($ips[$_SERVER['REMOTE_ADDR']]))
        //      array_push($ips,$_SERVER['REMOTE_ADDR']);
        //  $ips = addslashes(join(',',$ips));

        // TEMP hack for IPs
        $ips = $_SERVER['REMOTE_ADDR'];
            // Only one update per minute allowed.
        if (strtotime($row['lastping']) < strtotime("-1 minutes")) {
            // Increase the popularity of this server
                           $sql = "UPDATE " . Database::prefix("logdnet") .
                                   " SET lang='" . Database::escape($lang) .
                                   "',count='" . (int)$count . "',recentips='" . Database::escape($ips) .
                                   "',priority=priority+1,description='" . Database::escape($desc) .
                                   "',version='" . Database::escape($vers) .
                                   "',admin='" . Database::escape($admin) .
                                   "',lastupdate='$date',lastping='$date' WHERE serverid=" . (int)$row['serverid'];
                           Database::query($sql);
        }
    //  }
    } else {
        // This is a new server, so add it and give it a small priority boost.
               $sql = "INSERT INTO " . Database::prefix("logdnet") .
                       " (address,description,version,admin,priority,lastupdate,lastping,count,recentips,lang) VALUES ('" .
                       Database::escape($addy) . "','" .
                       Database::escape($desc) . "','" .
                       Database::escape($vers) . "','" .
                       Database::escape($admin) . "',10,'$date','$date','$count','" .
                       Database::escape($_SERVER['REMOTE_ADDR']) . "','" .
                       Database::escape($lang) . "')";
               $result = Database::query($sql);
    }

    // Do these next two things whether we've added a new server or
    // updated an old one

    // Delete servers older than a week
    $sql = "DELETE FROM " . Database::prefix("logdnet") . " WHERE lastping < '" . date("Y-m-d H:i:s", strtotime("-2 weeks")) . "'";
    Database::query($sql);

    // Degrade the popularity of any server which hasn't been updated in the
    // past 5 minutes by 1%.  This means that unpopular servers will fall
    // toward the bottom of the list.
    $since = date("Y-m-d H:i:s", strtotime("-5 minutes"));
    $sql = "UPDATE " .  Database::prefix("logdnet") . " SET priority=priority*0.99,lastupdate='" . date("Y-m-d H:i:s") . "' WHERE lastupdate < '$since'";
    Database::query($sql);

    //Now, if we're using version 2 of LoGDnet, we'll return the appropriate code.
    $v = httpget("v");
    if ((int)$v >= 2) {
        $currency = getsetting("paypalcurrency", "USD");
        $info = array();
        $info[''] = '<!--data from ' . $_SERVER['HTTP_HOST'] . '-->
<form action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
<input type="hidden" name="cmd" value="_xclick">
<input type="hidden" name="business" value="logd@mightye.org">
<input type="hidden" name="item_name" value="Legend of the Green Dragon Author Donation from %s">
<input type="hidden" name="item_number" value="%s">
<input type="hidden" name="no_shipping" value="1">
<input type="hidden" name="notify_url" value="http://lotgd.net/payment.php">
<input type="hidden" name="cn" value="Your Character Name">
<input type="hidden" name="cs" value="1">
<input type="hidden" name="currency_code" value="' . $currency . '">
<input type="hidden" name="tax" value="0">
<input type="image" src="images/logdnet.php" border="0" width="62" height="57" name="submit" alt="Donate!">
</form>';
        $info['image'] = join("", file("images/paypal1.gif"));
        $info['content-type'] = "image/gif";

        echo base64_encode(serialize($info));
    }
} elseif ($op == "net") {
    // Someone is requesting our list of servers, so give it to them.

    // I'm going to do a slightly niftier sort manually in a bit which always
    // pops the most recent 'official' versions to the top of the list.
    $sql = "SELECT address,description,version,admin,priority FROM " . Database::prefix("logdnet") . " WHERE lastping > '" . date("Y-m-d H:i:s", strtotime("-7 days")) . "'";
    $result = Database::query($sql);
    $rows = array();
    $number = Database::numRows($result);
    for ($i = 0; $i < $number; $i++) {
        $rows[] = Database::fetchAssoc($result);
    }
    $rows = apply_logdnet_bans($rows);
    usort($rows, "lotgdsort");

    // Okay, they are now sorted, so output them
    for ($i = 0; $i < count($rows); $i++) {
        $row = serialize($rows[$i]);
        echo $row . "\n";
    }
} else {
    Header::pageHeader("LoGD Net");
    Nav::add("Login page", "index.php");
    output("`@Below are a list of other LoGD servers that have registered with the LoGD Net.`n");
    output("`2It should be noted that this list is subject to editing and culling by the administrators of logdnet.logd.com. ");
    output("Normally this list is a comprehensive list of all servers that have elected to register with LoGDnet, but I'm making changes to that. ");
    output("Because this list is a free service provided by logdnet.logd.com, we reserve the right to remove those who we don't want in the list.`n");
    output("Reasons we might remove a server:`n");
    output("&#149; Altering our copyright statement outside of the provisions we have provided within the code,`n", true);
    output("&#149; Removing our PayPal link,`n", true);
    output("&#149; Providing deceptive, inappropriate, or false information in the server listing,`n", true);
    output("&#149; Not linking back to LoGDnet`n", true);
    output("Or really, any other reason that we want.`n");
    output("If you've been banned already, chances are you know why, and chances are we've got no interest in removing the ban.");
    output("We provide this free of charge, at the expense of considerable bandwidth and server load, so if you've had the gall to abuse our charity, don't expect it to be won back very easily.`n`n");
    output("If you are well behaved, we don't have an interest in blocking you from this listing. `0`n");
    rawoutput("<table border='0' cellpadding='1' cellspacing='0'>");
    rawoutput("<tr class='trhead'><td>");
    output("Server");
    rawoutput("</td><td>");
    output("Version");
    rawoutput("</td>");
    require_once __DIR__ . "/lib/pullurl.php";
    $servers = array();
    $u = getsetting("logdnetserver", "http://logdnet.logd.com/");
    $logdnet = getsetting('logdnet', 0);
    if (!preg_match("/\/$/", $u)) {
        $u = $u . "/";
        savesetting("logdnetserver", $u);
    }
    if ($logdnet && $u != "") {
        try {
            $servers = pullurl($u . "logdnet.php?op=net");
            if (!$servers) {
                $servers = array();
            }
            $i = 0;
            foreach ($servers as $val) {
                // Ensure we have a string value
                if (!is_string($val)) {
                    continue;
                }

                // Remove newlines from the pulled content
                $val = trim($val);
                if ($val === '') {
                    continue;
                }

                $row = safeUnserialize($val);
                // Logdnet failures are non-fatal and are skipped without user-facing errors.
                if ($row === false) {
                    continue;
                }

                if (!is_array($row)) {
                    static $reported = [];
                    $errorString = 'Invalid logdnet row: ' . $val;

                    if (!in_array($errorString, $reported, true)) {
                        $reported[] = $errorString;

                        if (getsetting('logdnet_error_notify', 1)) {
                            ErrorHandler::errorNotify(E_WARNING, $errorString, __FILE__, __LINE__, Backtrace::show());
                        }
                    } else {
                        debug($errorString);
                    }

                    continue;
                }

                // If we aren't given an address, continue on.
                if (
                    substr($row['address'], 0, 7) != "http://" &&
                    substr($row['address'], 0, 8) != "https://"
                ) {
                    continue;
                }

                // Give undescribed servers a boring descriptionn
                if (trim($row['description']) == "") {
                    $row['description'] = "Another boring and undescribed LotGD server";
                }

                // Strip out any embedded html.
                $row['description'] =
                    preg_replace("|<[a-zA-Z0-9/ =]+>|", "", $row['description']);

                // Clean up the desc
                $row['description'] = logdnet_sanitize($row['description']);
                $row['description'] = soap($row['description']);
                // Limit descs to 75 characters.
                if (strlen($row['description']) > 75) {
                    $row['description'] = substr($row['description'], 0, 75);
                }


//make valid
                $row['description'] = sanitize_mb($row['description']);
                $row['version'] = sanitize_mb($row['version']);


                $row['description'] = htmlentities(stripslashes($row['description']), ENT_COMPAT, getsetting("charset", "UTF-8"));
                $row['description'] = str_replace("`&amp;", "`&", $row['description']);

                // Correct for old logdnet servers
                if ($row['version'] == "") {
                    $row['version'] = translate_inline("Unknown");
                }

                // Output the information we have.
                rawoutput("<tr class='" . ($i % 2 == 0 ? "trlight" : "trdark") . "'>");
                rawoutput("<td><a href=\"" . HTMLEntities($row['address'], ENT_COMPAT, getsetting("charset", "UTF-8")) . "\" target='_blank'>");
                output_notl("`&%s`0", $row['description'], true);
                rawoutput("</a></td><td>");
                output_notl("`^%s`0", $row['version']); // so we are able to translate "`^Unknown`0"
                rawoutput("</td></tr>");
                $i++;
            }
        } catch (\Throwable $e) {
            if (getsetting('logdnet_error_notify', 1)) {
                ErrorHandler::errorNotify(
                    E_WARNING,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                    Backtrace::show($e->getTrace())
                );
            }
        }
    } elseif (!$logdnet) {
        rawoutput("<tr><td colspan='2'>");
        output("LoGDnet server listings are currently disabled.");
        rawoutput("</td></tr>");
    } else {
        rawoutput("<tr><td colspan='2'>");
        output("Sorry, no logdnet host server was defined in the game settings");
        rawoutput("</td></tr>");
    }
    rawoutput("</table>");
    Footer::pageFooter();
}

function safeUnserialize(string $val): array|false
{
    set_error_handler(static function () {
        return true;
    });

    try {
        $result = unserialize($val, ['allowed_classes' => false]);
    } catch (\Throwable $e) {
        $result = false;
    }

    restore_error_handler();

    return is_array($result) ? $result : false;
}

function apply_logdnet_bans($logdnet)
{
    $sql = "SELECT * FROM " . Database::prefix("logdnetbans");
    $result = Database::query($sql, "logdnetbans");
    while ($row = Database::fetchAssoc($result)) {
        reset($logdnet);
        foreach ($logdnet as $i => $net) {
            if (preg_match("/{$row['banvalue']}/i", $net[$row['bantype']])) {
                unset($logdnet[$i]);
            }
        }
    }
    return $logdnet;
}
