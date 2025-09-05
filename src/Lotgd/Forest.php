<?php

declare(strict_types=1);

/**
 * Wrapper around the old forest() function.
 */

namespace Lotgd;
use Lotgd\Settings;
use Lotgd\Nav\VillageNav;
use Lotgd\Modules\HookHandler;
use Lotgd\Nav;
use Lotgd\Output;
use Lotgd\Translator;

class Forest
{
    /**
     * Display the forest navigation and description.
     */
    public static function forest(bool $noshowmessage = false): void
    {
        global $session, $playermount;

        $settings = Settings::getInstance();

        Translator::getInstance()->setSchema('forest');

        Nav::add('Navigation');
        VillageNav::render();
        Nav::add('Heal');
        Nav::add('H?Healer\'s Hut', 'healer.php');
        Nav::add('Fight');
        Nav::add('L?Look for Something to Kill', 'forest.php?op=search');
        if ($session['user']['level'] > 1) {
            Nav::add('S?Go Slumming', 'forest.php?op=search&type=slum');
        }
        Nav::add('T?Go Thrillseeking', 'forest.php?op=search&type=thrill');
        if ($settings->getSetting('suicide', 0)) {
            if ($settings->getSetting('suicidedk', 10) <= $session['user']['dragonkills']) {
                Nav::add("*?Search `\$Suicidally`0", 'forest.php?op=search&type=suicide');
            }
        }
        Nav::add('Other');
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
                Nav::add('G?`@Seek Out the Green Dragon', 'forest.php?op=dragon');
            }
        }
        if (!$noshowmessage) {
            $output = Output::getInstance();
            $output->output('`c`7`bThe Forest`b`0`c');
            $output->output('The Forest, home to evil creatures and evildoers of all sorts.`n`n');
            $output->output('The thick foliage of the forest restricts your view to only a few yards in most places.');
            $output->output('The paths would be imperceptible except for your trained eye.');
            $output->output('You move as silently as a soft breeze across the thick moss covering the ground, wary to avoid stepping on a twig or any of the numerous pieces of bleached bone that populate the forest floor, lest you betray your presence to one of the vile beasts that wander the forest.`n');
            HookHandler::hook('forest-desc');
        }
        HookHandler::hook('forest', []);
        module_display_events('forest', 'forest.php');
        Translator::getInstance()->setSchema();
    }
}
