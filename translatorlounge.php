<?php

declare(strict_types=1);

use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Commentary;
use Lotgd\Http;
use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Nav;
use Lotgd\Translator;

// translator ready
// addnews ready
// mail ready
require_once __DIR__ . "/common.php";

SuAccess::check(SU_IS_TRANSLATOR);
Commentary::addCommentary();
Translator::getInstance()->setSchema("translatorlounge");

SuperuserNav::render();

$op = Http::get('op');
Header::pageHeader("Translator Lounge");

$output->output("`^You duck into a secret cave that few know about. ");
if ($session['user']['sex']) {
    $output->output("Inside you are greeted by the sight of numerous muscular bare-chested men who wave palm fronds at you and offer to feed you grapes as you lounge on Greco-Roman couches draped with silk.`n`n");
} else {
    $output->output("Inside you are greeted by the sight of numerous scantily clad buxom women who wave palm fronds at you and offer to feed you grapes as you lounge on Greco-Roman couches draped with silk.`n`n");
}
Commentary::commentDisplay("", "trans-lounge", "Engage in idle conversation with other translators:", 25);
Nav::add("Actions");
if ($session['user']['superuser'] & SU_IS_TRANSLATOR) {
    Nav::add("U?Untranslated Texts", "untranslated.php");
}

Footer::pageFooter();
