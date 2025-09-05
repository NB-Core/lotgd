<?php

declare(strict_types=1);

namespace Lotgd\Nav;

use Lotgd\Util\ScriptName;
use Lotgd\Modules\HookHandler;
use Lotgd\Translator;
use Lotgd\Nav as Navigation;

/**
 * Navigation helpers for superuser areas.
 */
class SuperuserNav
{
    /**
     * Render the common navigation links for superusers.
     */
    public static function render(): void
    {
        global $SCRIPT_NAME, $session;
        Translator::getInstance()->setSchema('nav');
        Navigation::add('Navigation');
        if ($session['user']['superuser'] & ~ SU_DOESNT_GIVE_GROTTO) {
            $script = ScriptName::current();
            if ($script != 'superuser') {
                $args = HookHandler::hook('grottonav');
                if (!array_key_exists('handled', $args) || !$args['handled']) {
                    Navigation::add('G?Return to the Grotto', 'superuser.php');
                }
            }
        }
        $args = HookHandler::hook('mundanenav');
        if (!array_key_exists('handled', $args) || !$args['handled']) {
            Navigation::add('M?Return to the Mundane', 'village.php');
        }
        Translator::getInstance()->setSchema();
    }
}
