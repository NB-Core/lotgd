<?php

use Lotgd\Forms;

function previewfield($name, $startdiv = false, $talkline = "says", $showcharsleft = true, $info = false, $script_output = true)
{
    return Forms::previewField($name, $startdiv, $talkline, $showcharsleft, $info, $script_output);
}
