<?php

declare(strict_types=1);

use Lotgd\Nav;
use Lotgd\Modules\HookHandler;

$output->output('`)`b`cThe Mausoleum`c`b');

$output->output('You enter the mausoleum and find yourself in a cold, stark marble chamber.');
$output->output('The air around you carries the chill of death itself.');
$output->output('From the darkness, two black eyes stare into your soul.');
$output->output('A clammy grasp seems to clutch your mind, and fill it with the words of the Overlord of Death, `\$%s`) himself.`n`n', $deathoverlord);

$output->output("\"`7Your mortal coil has forsaken you.  Now you turn to me.  There are those within this land that have eluded my grasp and possess a life beyond life.  To prove your worth to me and earn my favor, go out and torment their souls.  Should you gain enough of my favor, I will reward you.`)\"");
Nav::add('G?Return to the Graveyard', 'graveyard.php');
Nav::add('Places');
Nav::add('S?Land of the Shades', 'shades.php');
Nav::add('Souls');
Nav::add(["Question `\$%s`0 about the worth of your soul", $deathoverlord], 'graveyard.php?op=question');
Nav::add(["Restore Your Soul (%s favor)", $favortoheal], 'graveyard.php?op=restore');
HookHandler::hook('mausoleum');
