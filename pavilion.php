<?php

use Lotgd\Commentary;
use Lotgd\Translator;
use Lotgd\Nav\VillageNav;

require_once 'common.php';

// translator ready
// addnews ready
// mail ready

Translator::getInstance()->setSchema('pavilion');
Commentary::addCommentary();
checkday();

page_header('Eye-catching Pavilion');

output("`b`cThe Pavilion`c`b`n");
output("This page is a placeholder for beta features. Customize it to showcase experimental content.`n");

VillageNav::render();
Commentary::commentDisplay('', 'beta', 'Talk with other testers:', 25);

page_footer();
