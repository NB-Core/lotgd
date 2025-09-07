<?php

use Lotgd\Commentary;
use Lotgd\Translator;
use Lotgd\Output;
use Lotgd\Nav;
use Lotgd\Redirect;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";
$output = Output::getInstance();

Translator::getInstance()->setSchema("shades");

page_header("Land of the Shades");
Commentary::addCommentary();
checkday();

if ($session['user']['alive']) {
    Redirect::redirect("village.php");
}
$output->output("`\$You walk among the dead now, you are a shade. ");
$output->output("Everywhere around you are the souls of those who have fallen in battle, in old age, and in grievous accidents. ");
$output->output("Each bears telltale signs of the means by which they met their end.`n`n");
$output->output("Their souls whisper their torments, haunting your mind with their despair:`n");

$output->output("`nA sepulchral voice intones, \"`QIt is now %s in the world above.`\$\"`n`n", getgametime());
Nav::add("Log Out");
Nav::add("Log out", "login.php?op=logout");

Nav::add("Places");
Nav::add("The Graveyard", "graveyard.php");

Nav::add("Return to the news", "news.php");

modulehook("shades", array()); // if this is too low, you can use footer-shades...

Commentary::commentDisplay("`n`QNearby, some lost souls lament:`n", "shade", "Despair", 25, "despairs");

Translator::getInstance()->setSchema("nav");

// the mute module blocks players from speaking until they
// read the FAQs, and if they first try to speak when dead
// there is no way for them to unmute themselves without this link.
Nav::add("Other");
Nav::add("??F.A.Q. (Frequently Asked Questions)", "petition.php?op=faq", false, true);
Nav::add("A?Account Info", "account.php");
Nav::add("P?Preferences", "prefs.php");

if ($session['user']['superuser'] & SU_EDIT_COMMENTS) {
    Nav::add("Superuser");
    Nav::add(",?Comment Moderation", "moderate.php");
}
if ($session['user']['superuser'] & ~SU_DOESNT_GIVE_GROTTO) {
    Nav::add("Superuser");
    Nav::add("X?Superuser Grotto", "superuser.php");
}
if ($session['user']['superuser'] & SU_INFINITE_DAYS) {
    Nav::add("Superuser");
    Nav::add("/?New Day", "newday.php");
}

Translator::getInstance()->setSchema();

page_footer();
