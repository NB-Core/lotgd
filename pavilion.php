<?php

use Lotgd\Commentary;
use Lotgd\Translator;

require_once 'common.php';
require_once 'lib/villagenav.php';

// translator ready
// addnews ready
// mail ready

Translator::getInstance()->setSchema('pavilion');
Commentary::addCommentary();
checkday();

page_header('Eye-catching Pavilion');

output("`b`cThe Pavilion`c`b`n");
output("This page is a placeholder for beta features. Customize it to showcase experimental content.`n");

villagenav();
Commentary::commentDisplay('', 'beta', 'Talk with other testers:', 25);

page_footer();
