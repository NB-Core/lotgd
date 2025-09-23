<?php

use Lotgd\Commentary;
use Lotgd\DateTime;
use Lotgd\Nav\VillageNav;
use Lotgd\Output;
use Lotgd\Page\Footer;
use Lotgd\Page\Header;
use Lotgd\Translator;

require_once __DIR__ . '/common.php';

$output = Output::getInstance();

// translator ready
// addnews ready
// mail ready

Translator::getInstance()->setSchema('pavilion');
Commentary::addCommentary();
DateTime::checkDay();

Header::pageHeader('Eye-catching Pavilion');

$output->output("`b`cThe Pavilion`c`b`n");
$output->output("This page is a placeholder for beta features. Customize it to showcase experimental content.`n");

VillageNav::render();
Commentary::commentDisplay('', 'beta', 'Talk with other testers:', 25);

Footer::pageFooter();
