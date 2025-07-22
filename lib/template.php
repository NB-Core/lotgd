<?php

use Lotgd\Template;

function templatereplace($itemname, $vals = false)
{
    return Template::templateReplace($itemname, $vals);
}
function prepare_template($force = false)
{
    Template::prepareTemplate($force);
}
