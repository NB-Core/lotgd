<?php
use Lotgd\Battle;

function show_enemies($enemies){ return Battle::showEnemies($enemies); }
function prepare_fight($options=false){ return Battle::prepareFight($options); }
function prepare_companions(){ return Battle::prepareCompanions(); }
function suspend_companions($s,$n=false){ return Battle::suspendCompanions($s,$n); }
function unsuspend_companions($s,$n=false){ return Battle::unsuspendCompanions($s,$n); }
function autosettarget($localenemies){ return Battle::autoSetTarget($localenemies); }
function report_companion_move(&$badguy,$companion,$act="fight"){ return Battle::reportCompanionMove($badguy,$companion,$act); }
function rollcompaniondamage(&$badguy,$companion){ return Battle::rollCompanionDamage($badguy,$companion); }
function battle_spawn($creature){ return Battle::battleSpawn($creature); }
function battle_heal($amount,$target=false){ return Battle::battleHeal($amount,$target); }
function execute_ai_script($script){ return Battle::executeAiScript($script); }

