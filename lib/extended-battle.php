<?php
use Lotgd\Battle;

function show_enemies($enemies){ return Battle::showEnemies($enemies); }
function prepare_fight($options=false){ return Battle::prepare_fight($options); }
function prepare_companions(){ return Battle::prepare_companions(); }
function suspend_companions($s,$n=false){ return Battle::suspend_companions($s,$n); }
function unsuspend_companions($s,$n=false){ return Battle::unsuspend_companions($s,$n); }
function autosettarget($localenemies){ return Battle::autosettarget($localenemies); }
function report_companion_move(&$badguy,$companion,$act="fight"){ return Battle::report_companion_move($badguy,$companion,$act); }
function rollcompaniondamage(&$badguy,$companion){ return Battle::rollcompaniondamage($badguy,$companion); }
function battle_spawn($creature){ return Battle::battle_spawn($creature); }
function battle_heal($amount,$target=false){ return Battle::battle_heal($amount,$target); }
function execute_ai_script($script){ return Battle::execute_ai_script($script); }

