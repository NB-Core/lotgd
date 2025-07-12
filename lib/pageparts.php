<?php

declare(strict_types=1);

use Lotgd\PageParts;

function page_header(...$args){ PageParts::pageHeader(...$args); }
function popup(string $page, string $size="550x300"){ return PageParts::popup($page, $size); }
function page_footer(bool $saveuser=true){ PageParts::pageFooter($saveuser); }
function popup_header(...$args){ PageParts::popupHeader(...$args); }
function popup_footer(){ PageParts::popupFooter(); }
function wipe_charstats(): void { PageParts::wipeCharStats(); }
function addcharstat(string $label, mixed $value = null): void { PageParts::addCharStat($label, $value); }
function getcharstat(string $cat, string $label){ return PageParts::getCharStat($cat, $label); }
function setcharstat(string $cat, string $label, mixed $val): void { PageParts::setCharStat($cat, $label, $val); }
function getcharstats(string $buffs): string{ return PageParts::getCharStats($buffs); }
function getcharstat_value(string $section,string $title){ return PageParts::getCharStatValue($section, $title); }
function charstats(): string{ return PageParts::charStats(); }
function loadtemplate($templatename){ return PageParts::loadTemplate($templatename); }
function maillink(){ return PageParts::mailLink(); }
function maillinktabtext(){ return PageParts::mailLinkTabText(); }
function motdlink(){ return PageParts::motdLink(); }
