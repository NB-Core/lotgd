<?php

declare(strict_types=1);

use Lotgd\Battle;
use Lotgd\BellRand;
use Lotgd\PlayerFunctions;
use Lotgd\Modules\HookHandler;
use Lotgd\Nav;
use Lotgd\Http;
use Lotgd\Page\Footer;
use Lotgd\MySQL\Database;

if ($session['user']['gravefights'] <= 0) {
    $output->output("`\$`bYour soul can bear no more torment in this afterlife.`b`0");
    $op = '';
    Http::set('op', '');
} else {
    Battle::suspendCompanions('allowinshades', true);
    if (HookHandler::moduleEvents('graveyard', (int) getsetting('gravechance', 0)) != 0) {
        if (! Nav::checkNavs()) {
            // If we're going back to the graveyard, make sure to reset
            // the special and the specialmisc
            $session['user']['specialinc'] = "";
            $session['user']['specialmisc'] = "";
            $skipgraveyardtext = true;
            $op = '';
            Http::set('op', '');
        } else {
            Footer::pageFooter();
        }
    } else {
        $session['user']['gravefights']--;
            $battle = true;
            $sql = "SELECT * FROM " . Database::prefix('creatures') . " WHERE graveyard=1 ORDER BY rand(" . e_rand() . ") LIMIT 1";
        $result = Database::query($sql);
        $badguy = Database::fetchAssoc($result);
        $level = $session['user']['level'];
        $shift = 0;
        if ($level < 5) {
            $shift = -1;
        }
        $badguy['creatureattack'] = 9 + $shift + (int)(($level - 1) * 1.5);

        // Make graveyard creatures easier.
        $badguy['creaturedefense'] = (int)((9 + $shift + (($level - 1) * 1.5)));
        $badguy['creaturedefense'] *= .8;
        if (isset($badguy['creatureaiscript'])) {
            $aiscriptfile = "scripts/" . $badguy['creatureaiscript'] . ".php";
            if (file_exists($aiscriptfile)) {
                //file there, get content and put it into the ai script field.
                $badguy['creatureaiscript'] = "require('" . $aiscriptfile . "');";
            }
        }
        $atk = PlayerFunctions::getPlayerAttack();
        $def = PlayerFunctions::getPlayerDefense();
        //$badguy['creatureattack']*=1.7+round($atk/(10 + round(($session['user']['level'] - 1) )));
        //$badguy['creaturedefense']*=1.6+round($def/(10 + round(($session['user']['level'] - 1))));
        $modificator = 1.5;
        $badguy['creatureattack'] = max(2, $atk - BellRand::generate(2, ceil($level / $modificator) + $modificator * sqrt($session['user']['dragonkills'])));
        $badguy['creaturedefense'] = max(2, $def - BellRand::generate(2, ceil($level / $modificator) + $modificator * sqrt($session['user']['dragonkills'])));
        $badguy['creaturehealth'] = $level * 5 + 50 + round($session['user']['dragonkills'] * 2);
        $badguy['creatureexp'] = e_rand(10, 15) + round($level / 3);
        $badguy['creaturelevel'] = $level;
        $attackstack['enemies'][0] = $badguy;
        $attackstack['options']['type'] = 'graveyard';


        //no multifights currently, so this hook passes the badguy to modify
        $attackstack = HookHandler::hook('graveyardfight-start', $attackstack);

        $session['user']['badguy'] = createstring($attackstack);
    }
}
