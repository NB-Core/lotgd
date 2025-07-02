<?php
use Lotgd\GameLog;
function gamelog($message,$category="general",$filed=false){
    GameLog::log($message,$category,$filed);
}
?>
