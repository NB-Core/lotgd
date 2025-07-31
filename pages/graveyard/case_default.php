<?php

declare(strict_types=1);

use Lotgd\Nav;
use Lotgd\Modules\HookHandler;

if (! $skipgraveyardtext) {
    $output->output("`)`c`bThe Graveyard`b`c");
    $output->output(
        "Your spirit wanders into a lonely graveyard, overgrown with sickly weeds which seem to grab at your spirit as you float past them."
    );
    $output->output(
        "Around you are the remains of many broken tombstones, some lying on their faces, some shattered to pieces."
    );
    $output->output(
        "You can almost hear the wails of the souls trapped within each plot lamenting their fates.`n`n"
    );
    $output->output(
        "In the center of the graveyard is an ancient looking mausoleum which has been worn by the effects of untold years."
    );
    $output->output(
        "A sinister looking gargoyle adorns the apex of its roof; its eyes seem to follow  you, and its mouth gapes with sharp stone teeth."
    );
    $output->output("The plaque above the door reads `\$%s`), Overlord of Death`).", $deathoverlord);
    HookHandler::hook('graveyard-desc');
}
Nav::add('S?Return to the Shades', 'shades.php');
if ($session['user']['gravefights']) {
    Nav::add('Torment');
    Nav::add('Look for Something to Torment', 'graveyard.php?op=search');
}
Nav::add('Places');
Nav::add('W?List Warriors', 'list.php');
Nav::add('M?Enter the Mausoleum', 'graveyard.php?op=enter');
HookHandler::hook('graveyard');
HookHandler::displayEvents('graveyard', 'graveyard.php');
