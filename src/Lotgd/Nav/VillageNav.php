<?php

declare(strict_types=1);

namespace Lotgd\Nav;

use Lotgd\Modules\HookHandler;
use Lotgd\Translator;

/**
 * Navigation helper for returning to the village.
 */
class VillageNav
{
    public static function render($extra = false): void
    {
        global $session;
        $loc = $session['user']['location'] ?? '';
        if ($extra === false) {
            $extra = '';
        }
        $args = HookHandler::hook('villagenav');
        if (array_key_exists('handled', $args) && $args['handled']) {
            return;
        }
        Translator::getInstance()->setSchema('nav');
        if ($session['user']['alive']) {
            addnav(["V?Return to %s", $loc], "village.php$extra");
        } else {
            addnav('S?Return to the Shades', 'shades.php');
        }
        Translator::getInstance()->setSchema();
    }
}
