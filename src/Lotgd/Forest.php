<?php

declare(strict_types=1);

/**
 * Wrapper around the old forest() function.
 */

namespace Lotgd;

use Lotgd\Nav\VillageNav;
use Lotgd\Modules\HookHandler;
use Lotgd\Translator;

class Forest
{
    /**
     * Display the forest navigation and description.
     */
    public static function forest(bool $noshowmessage = false): void
    {
        global $session, $playermount;
        Translator::getInstance()->setSchema('forest');
        addnav('Navigation');
        VillageNav::render();
        addnav('Heal');
        addnav('H?Healer\'s Hut', 'healer.php');
        addnav('Fight');
        addnav('L?Look for Something to Kill', 'forest.php?op=search');
        if ($session['user']['level'] > 1) {
            addnav('S?Go Slumming', 'forest.php?op=search&type=slum');
        }
        addnav('T?Go Thrillseeking', 'forest.php?op=search&type=thrill');
        if (getsetting('suicide', 0)) {
            if (getsetting('suicidedk', 10) <= $session['user']['dragonkills']) {
                addnav("*?Search `\$Suicidally`0", 'forest.php?op=search&type=suicide');
            }
        }
        addnav('Other');
        if ($session['user']['level'] >= getsetting('maxlevel', 15) && $session['user']['seendragon'] == 0) {
            $isforest = 0;
            $vloc = HookHandler::hook('validforestloc', []);
            foreach ($vloc as $i => $l) {
                if ($session['user']['location'] == $i) {
                    $isforest = 1;
                    break;
                }
            }
            if ($isforest || count($vloc) == 0) {
                addnav('G?`@Seek Out the Green Dragon', 'forest.php?op=dragon');
            }
        }
        if (!$noshowmessage) {
            output('`c`7`bThe Forest`b`0`c');
            output('The Forest, home to evil creatures and evildoers of all sorts.`n`n');
            output('The thick foliage of the forest restricts your view to only a few yards in most places.');
            output('The paths would be imperceptible except for your trained eye.');
            output('You move as silently as a soft breeze across the thick moss covering the ground, wary to avoid stepping on a twig or any of the numerous pieces of bleached bone that populate the forest floor, lest you betray your presence to one of the vile beasts that wander the forest.`n');
            HookHandler::hook('forest-desc');
        }
        HookHandler::hook('forest', []);
        module_display_events('forest', 'forest.php');
        Translator::getInstance()->setSchema();
    }
}
