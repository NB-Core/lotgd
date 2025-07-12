<?php
use Lotgd\Commentary;
require_once 'common.php';
require_once 'lib/villagenav.php';

// translator ready
// addnews ready
// mail ready

tlschema('pavilion');
Commentary::addcommentary();
checkday();

page_header('Eye-catching Pavilion');

output("`b`cThe Pavilion`c`b`n");
output("This page is a placeholder for beta features. Customize it to showcase experimental content.`n");

villagenav();
Commentary::commentdisplay('', 'beta', 'Talk with other testers:', 25);

page_footer();
