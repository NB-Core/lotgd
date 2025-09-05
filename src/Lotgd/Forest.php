<?php

declare(strict_types=1);

/**
 * Wrapper around the old forest() function.
 */

namespace Lotgd;

use Lotgd\Nav as Navigation;
use Lotgd\Nav\VillageNav;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Modules\HookHandler;
use Lotgd\Translator;

class Forest
{
    /**
     * Display the forest navigation and description.
     */
    public static function forest(bool $noshowmessage = false): void
    {
        global $session;

        $settings = Settings::getInstance();

        $translator = Translator::getInstance();
        $translator->setSchema('forest');

        $output = Output::getInstance();

        Navigation::add('Navigation');
        VillageNav::render();
        Navigation::add('Heal');
        Navigation::add('H?Healer\'s Hut', 'healer.php');
        Navigation::add('Fight');
        Navigation::add('L?Look for Something to Kill', 'forest.php?op=search');
        if ($session['user']['level'] > 1) {
            Navigation::add('S?Go Slumming', 'forest.php?op=search&type=slum');
        }
        Navigation::add('T?Go Thrillseeking', 'forest.php?op=search&type=thrill');
        if ($settings->getSetting('suicide', 0)) {
            if ($settings->getSetting('suicidedk', 10) <= $session['user']['dragonkills']) {
                Navigation::add("*?Search `\$Suicidally`0", 'forest.php?op=search&type=suicide');
            }
        }
        Navigation::add('Other');
        if ($session['user']['level'] >= $settings->getSetting('maxlevel', 15) && $session['user']['seendragon'] == 0) {
            $isforest = 0;
            $vloc = HookHandler::hook('validforestloc', []);
            foreach ($vloc as $i => $l) {
                if ($session['user']['location'] == $i) {
                    $isforest = 1;
                    break;
                }
            }
            if ($isforest || count($vloc) == 0) {
                Navigation::add('G?`@Seek Out the Green Dragon', 'forest.php?op=dragon');
            }
        }
        if (!$noshowmessage) {
            $output->output('`c`7`bThe Forest`b`0`c');
            $output->output('The Forest, home to evil creatures and evildoers of all sorts.`n`n');
            $output->output('The thick foliage of the forest restricts your view to only a few yards in most places.');
            $output->output('The paths would be imperceptible except for your trained eye.');
            $output->output('You move as silently as a soft breeze across the thick moss covering the ground, wary to avoid stepping on a twig or any of the numerous pieces of bleached bone that populate the forest floor, lest you betray your presence to one of the vile beasts that wander the forest.`n');
            HookHandler::hook('forest-desc');
        }
        HookHandler::hook('forest', []);
        HookHandler::displayEvents('forest', 'forest.php');
        $translator->setSchema();
    }
}
