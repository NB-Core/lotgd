<?php
declare(strict_types=1);
namespace Lotgd\Nav;

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
        tlschema('nav');
        addnav('Navigation');
        if ($session['user']['superuser'] &~ SU_DOESNT_GIVE_GROTTO) {
            $script = substr($SCRIPT_NAME, 0, strpos($SCRIPT_NAME, '.'));
            if ($script != 'superuser') {
                $args = modulehook('grottonav');
                if (!array_key_exists('handled', $args) || !$args['handled']) {
                    addnav('G?Return to the Grotto', 'superuser.php');
                }
            }
        }
        $args = modulehook('mundanenav');
        if (!array_key_exists('handled', $args) || !$args['handled']) {
            addnav('M?Return to the Mundane', 'village.php');
        }
        tlschema();
    }
}
