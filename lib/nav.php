<?php

// Legacy wrapper for \Lotgd\Nav class

use Lotgd\Nav;

/**
 * Proxy for \Lotgd\Nav::blockNav().
 */
function blocknav(string $link, bool $partial = false): void
{
    Nav::blockNav($link, $partial);
}

/**
 * Proxy for \Lotgd\Nav::unblockNav().
 */
function unblocknav(string $link, bool $partial = false): void
{
    Nav::unblockNav($link, $partial);
}

/**
 * Append the session counter to a URL.
 */
function appendcount(string $link): string
{
    return Nav::appendCount($link);
}

/**
 * Append a query string to a URL.
 */
function appendlink(string $link, string $new): string
{
    return Nav::appendLink($link, $new);
}

/**
 * Prevent or allow adding new navigation entries.
 */
function set_block_new_navs(bool $block): void
{
    Nav::setBlockNewNavs($block);
}

/**
 * Start a new navigation section.
 */
function addnavheader($text, bool $collapse = true, bool $translate = true): void
{
    Nav::addHeader($text, $collapse, $translate);
}

/**
 * Start a new sub navigation section.
 */
function addnavsubheader($text, bool $translate = true): void
{
    Nav::addSubHeader($text, $translate);
}

/**
 * Start a coloured sub navigation section.
 */
function addnavcoloredsubheader(string $text, bool $translate = true): void
{
    Nav::addColoredSubHeader($text, $translate);
}

/**
 * Add a navigation link without translation.
 */
function addnav_notl($text, $link = false, $priv = false, $pop = false, $popsize = '500x300'): void
{
    Nav::addNotl($text, $link, $priv, $pop, $popsize);
}

/**
 * Add a navigation link.
 */
function addnav($text, $link = false, $priv = false, $pop = false, $popsize = '500x300'): void
{
    Nav::add($text, $link, $priv, $pop, $popsize);
}

/**
 * Check if a link is blocked.
 */
function is_blocked(string $link): bool
{
    return Nav::isBlocked($link);
}

/**
 * Count available links in a section.
 */
function count_viable_navs($section): int
{
    return Nav::countViableNavs($section);
}

/**
 * Determine if any navigation exists.
 */
function checknavs(): bool
{
    return Nav::checkNavs();
}

/**
 * Render all navigation sections.
 */
function buildnavs(): string
{
    return Nav::buildNavs();
}

/**
 * Get the total number of nav entries created.
 */
function navcount(): int
{
    return Nav::navCount();
}

/**
 * Reset allowed navigation links.
 */
function clearnav(): void
{
    Nav::clearNav();
}

/**
 * Sort navigation entries alphabetically.
 */
function navsort(string $sectionOrder = 'asc', string $subOrder = 'asc', string $itemOrder = 'asc'): void
{
    Nav::navSort($sectionOrder, $subOrder, $itemOrder);
}

/**
 * Clear output buffers and reset navigation.
 */
function clearoutput(): void
{
    Nav::clearOutput();
}
