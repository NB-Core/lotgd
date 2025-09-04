<?php

declare(strict_types=1);

use Lotgd\Page\Header;
use Lotgd\Modules\HookHandler;
use Lotgd\Translator;

Translator::getInstance()->setSchema('faq');
Header::popupHeader("Frequently Asked Questions (FAQ)");
$output->output("`^Welcome to Legend of the Green Dragon.`n`n");
$output->output("`@You wake up one day, and you're in a village for some reason.");
$output->output("You wander around, bemused, until you stumble upon the main village square.");
$output->output("Once there you start asking lots of stupid questions.");
$output->output("People (who are mostly naked for some reason) throw things at you.");
$output->output("You escape by ducking into a nearby building and find a rack of pamphlets by the door.");
$output->output("The title of the pamphlet reads: `&\"Everything You Wanted to Know About the LotGD, but Were Afraid to Ask.\"");
$output->output("`@Looking furtively around to make sure nobody's watching, you open one and read:`n`n");
$output->output("\"`#So, you're a Newbie.  Welcome to the club.");
$output->output("Here you will find answers to the questions that plague you.");
$output->output("Well, actually you will find answers to the questions that plagued US.");
$output->output("So, here, read and learn, and leave us alone!`@\"`n`n");
$output->output("`^`bContents:`b`0`n");

HookHandler::hook("faq-pretoc");
$output->output("`^`bNew Player & FAQ`b`0`n");
$t = Translator::translateInline("`@New Player Primer`0");
$output->outputNotl("&#149;<a href='petition.php?op=primer'>%s</a><br/>", $t, true);
$t = Translator::translateInline("`@Frequently Asked Questions on Game Play (General)`0");
$output->outputNotl("&#149;<a href='petition.php?op=faq1'>%s</a><br/>", $t, true);
$t = Translator::translateInline("`@Frequently Asked Questions on Game Play (with spoilers)`0");
$output->outputNotl("&#149;<a href='petition.php?op=faq2'>%s</a><br/>", $t, true);
$t = Translator::translateInline("`@Frequently Asked Questions on Technical Issues`0");
$output->outputNotl("&#149;<a href='petition.php?op=faq3'>%s</a><br/>", $t, true);
HookHandler::hook("faq-toc");
HookHandler::hook("faq-posttoc");
$output->output("`nThank you,`nthe Management.`n");
