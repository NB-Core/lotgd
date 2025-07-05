<?php
// Legacy wrapper for \Lotgd\Nav class

use Lotgd\Nav;

function blocknav(string $link, bool $partial = false)
{
    Nav::blockNav($link, $partial);
}

function unblocknav(string $link, bool $partial = false)
{
    Nav::unblockNav($link, $partial);
}

function appendcount(string $link): string
{
    return Nav::appendCount($link);
}

function appendlink(string $link, string $new): string
{
    return Nav::appendLink($link, $new);
}

function set_block_new_navs(bool $block): void
{
    Nav::setBlockNewNavs($block);
}

function addnavheader($text, bool $collapse = true, bool $translate = true): void
{
    Nav::addHeader($text, $collapse, $translate);
}

function addnav_notl($text, $link = false, $priv = false, $pop = false, $popsize = '500x300'): void
{
    Nav::addNotl($text, $link, $priv, $pop, $popsize);
}

function addnav($text, $link = false, $priv = false, $pop = false, $popsize = '500x300'): void
{
    Nav::add($text, $link, $priv, $pop, $popsize);
}

function is_blocked(string $link): bool
{
    return Nav::isBlocked($link);
}

function count_viable_navs($section): int
{
    return Nav::countViableNavs($section);
}

function checknavs(): bool
{
    return Nav::checkNavs();
}

function buildnavs(): string
{
    return Nav::buildNavs();
}

function navcount(): int
{
    return Nav::navCount();
}

function clearnav(): void
{
    Nav::clearNav();
}

function navsort(): void
{
    Nav::navSort();
}

function clearoutput(): void
{
    Nav::clearOutput();
}
