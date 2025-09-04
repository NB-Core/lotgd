<?php

declare(strict_types=1);

use Lotgd\Commentary;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\Nav\VillageNav;
use Lotgd\DateTime;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready
require_once 'common.php';

Translator::getInstance()->setSchema('rock');

// This idea is Imusade's from lotgd.net
if (
    $session['user']['dragonkills'] > 0 ||
        $session['user']['superuser'] & SU_EDIT_COMMENTS
) {
    Commentary::addCommentary();
}

DateTime::checkDay();
Nav::add('Navigation');
VillageNav::render();
if (
    $session['user']['dragonkills'] > 0 ||
        $session['user']['superuser'] & SU_EDIT_COMMENTS
) {
    Header::pageHeader('The Veteran\'s Club');

    $output->output("`b`c`2The Veteran's Club`0`c`b");

    $output->output("`n`n`4Something in you compels you to examine the curious rock.  Some dark magic, locked up in age old horrors.`n`n");
    $output->output("When you arrive at the rock, an old scar on your arm begins to throb in succession with a mysterious light that now seems to come from the rock.  ");
    $output->output("As you stare at it, the rock shimmers, shaking off an illusion.  You realize that this is more than a rock.  ");
    $output->output("It is, in fact, a doorway, and over the threshold you see others bearing an identical scar to yours.  ");
    $output->output("It somehow reminds you of the head of one of the great serpents from legend.`n`n");
    $output->output("You have discovered The Veteran's Club.`n`n");

    modulehook("rock");

    Commentary::commentDisplay("", "veterans", "Boast here", 30, "boasts");
} else {
    Header::pageHeader('Curious looking rock');
    $output->output("You approach the curious looking rock.  ");
    $output->output("After staring and looking at it for a little while, it continues to look just like a curious looking rock.`n`n");
    $output->output("Bored, you decide to leave the rock alone.");
}


Footer::pageFooter();
