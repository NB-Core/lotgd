<?php
use Lotgd\SuAccess;
use Lotgd\Nav\SuperuserNav;
use Lotgd\Commentary;
// translator ready
// addnews ready
// mail ready
require_once("common.php");
require_once("lib/sanitize.php");
require_once("lib/http.php");

SuAccess::check(SU_IS_TRANSLATOR);
Commentary::addCommentary();
tlschema("translatorlounge");

SuperuserNav::render();

$op = httpget('op');
page_header("Translator Lounge");

output("`^You duck into a secret cave that few know about. ");
if ($session['user']['sex']){
  	output("Inside you are greeted by the sight of numerous muscular bare-chested men who wave palm fronds at you and offer to feed you grapes as you lounge on Greco-Roman couches draped with silk.`n`n");
}else{
	output("Inside you are greeted by the sight of numerous scantily clad buxom women who wave palm fronds at you and offer to feed you grapes as you lounge on Greco-Roman couches draped with silk.`n`n");
}
Commentary::commentDisplay("", "trans-lounge","Engage in idle conversation with other translators:",25);
addnav("Actions");
if ($session['user']['superuser'] & SU_IS_TRANSLATOR) addnav("U?Untranslated Texts", "untranslated.php");

page_footer();
?>
