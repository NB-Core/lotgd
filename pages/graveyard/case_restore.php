<?php

declare(strict_types=1);

use Lotgd\Nav;

$output->output('`)`b`cThe Mausoleum`c`b');
if ($session['user']['soulpoints'] < $max) {
    if ($session['user']['deathpower'] >= $favortoheal) {
        $output->output("`\$%s`) calls you weak for needing restoration, but as you have enough favor with him, he grants your request at the cost of `4%s`) favor.", $deathoverlord, $favortoheal);
        $session['user']['deathpower'] -= $favortoheal;
        $session['user']['soulpoints'] = $max;
    } else {
        $output->output("`\$%s`) curses you and throws you from the Mausoleum, you must gain more favor with him before he will grant restoration.", $deathoverlord);
    }
} else {
    $output->output("`\$%s`) sighs and mumbles something about, \"`7just 'cause they're dead, does that mean they don't have to think?`)\"`n`n", $deathoverlord);
    $output->output("Perhaps you'd like to actually `ineed`i restoration before you ask for it.");
}
Nav::add('G?Return to the Graveyard', 'graveyard.php');
Nav::add('Places');
Nav::add('S?Land of the Shades', 'shades.php');
Nav::add('Souls');
Nav::add(["Question `\$%s`0 about the worth of your soul", $deathoverlord], 'graveyard.php?op=question');
