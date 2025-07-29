<?php

declare(strict_types=1);

use Lotgd\Nav;
use Lotgd\Modules\HookHandler;

Nav::add('G?Return to the Graveyard', 'graveyard.php');
Nav::add('Places');
Nav::add('S?Land of the Shades', 'shades.php');
Nav::add('Souls');
if ($favortoheal > 0) {
    Nav::add(['Restore Your Soul (%s favor)', $favortoheal], 'graveyard.php?op=restore');
}


$hauntcost = getsetting('hauntcost', 25);
$resurrectioncost = getsetting('resurrectioncost', 100);

$default_actions = array();
$default_actions[] = array(
    "link" => "graveyard.php?op=resurrection",
    "linktext" => "Resurrection",
    "linkhardcoded" => 1,
    "favor" => getsetting('resurrectioncost', 100),
    "text" => "",
    "titletext" => "`\${deathoverlord}`) speaks, \"`7You have impressed me indeed.  I shall grant you the ability to visit your foes in the mortal world.`)\""
    );

//build navigation
$actions = HookHandler::hook('deathoverlord_actions', $default_actions);


foreach ($actions as $key => $row) {
    if ($row['favor'] > $session['user']['deathpower']) {
        if (!isset($row['hidden']) || !$row['hidden']) {
            continue; //strip the not buyable and hidden
        }
        $row['link'] = "";
    }
    $linktext[$key] = $row['linktext']; //linktext to use
    if (!isset($row['linkhardcoded']) || !$row['linkhardcoded']) {
        $linklist[$key] = ($row['link'] > "" ? "runmodule.php?module=" . $row['link'] : ""); //link to use
    } else {
        $linklist[$key] = $row['link'];
    }
    $favorcostlist[$key] = $row['favor']; //cost of favor
    $textlist[$key] = $row['text']; //text to output in the body
    $overlord[$key] = $row['titletext']; //text if this is the highest possible buy
}

if (isset($linktext) && is_array($linktext)) {
    $length = count($linktext);
} else {
    $length = 0;
}

//sort entries low to high
if ($length > 0) {
    array_multisort($favorcostlist, SORT_ASC, $linklist, $textlist, $overlord, $linktext);
}

$highest = translate_inline("`\${deathoverlord}`) speaks, \"`7I am not yet impressed with your efforts.  Continue my work, and we may speak further.`)");

if ($length > 0) {
    $reverse = array_reverse($overlord);
    foreach ($reverse as $text) {
        if ($text == "") {
            continue;
        }
        $highest = $text;
        break;
    }
}
$highest = str_replace("{deathoverlord}", $deathoverlord, $highest);
$output->outputNotl($highest . "`n`n");


Nav::add(["%s Favors", sanitize($deathoverlord)]);
for ($i = 0; $i < $length; $i++) {
    $linktext[$i] = str_replace("{deathoverlord}", $deathoverlord, $linktext[$i]);
    Nav::add(["%s`) (%s favors)", $linktext[$i], $favorcostlist[$i]], $linklist[$i]);
    if (isset($textlist[$i]) && $textlist[$i] != "") {
        $textlist[$i] = str_replace("{deathoverlord}", $deathoverlord, $textlist[$i]);
        $output->outputNotl($textlist[$i]);
    }
}
Nav::add('Other');
HookHandler::hook('ramiusfavors');

$output->output("`n`nYou have `6%s`) favor with `\$%s`).", $session['user']['deathpower'], $deathoverlord);
