<?php

declare(strict_types=1);

use Lotgd\Page\Header;
use Lotgd\Page\Footer;
use Lotgd\Page\CharStats;
use Lotgd\PageParts;

function page_header(...$args)
{
    Header::pageHeader(...$args);
}
function popup(string $page, string $size = "550x300")
{
    return PageParts::popup($page, $size);
}
function page_footer(bool $saveuser = true)
{
    Footer::pageFooter($saveuser);
}
function popup_header(...$args)
{
    Header::popupHeader(...$args);
}
function popup_footer()
{
    Footer::popupFooter();
}
function wipe_charstats(): void
{
    CharStats::wipe();
}
function addcharstat(string $label, mixed $value = null): void
{
    CharStats::add($label, $value);
}
function getcharstat(string $cat, string $label)
{
    return CharStats::get($cat, $label);
}
function setcharstat(string $cat, string $label, mixed $val): void
{
    CharStats::set($cat, $label, $val);
}
function getcharstats(string $buffs): string
{
    return CharStats::render($buffs);
}
function getcharstat_value(string $section, string $title)
{
    return CharStats::value($section, $title);
}
function charstats(): string
{
    return CharStats::display();
}
function loadtemplate($templatename)
{
    return PageParts::loadTemplate($templatename);
}
function maillink()
{
    return PageParts::mailLink();
}
function maillinktabtext()
{
    return PageParts::mailLinkTabText();
}
function motdlink()
{
    return PageParts::motdLink();
}
