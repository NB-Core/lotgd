<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Output helper for including the DOM utility script.
 */
class EDom
{
    /**
     * Print the script tag to include the JavaScript DOM helpers.
     */
    public static function includeScript(): void
    {
        Output::getInstance()->rawOutput("<script src='src/Lotgd/e_dom.js' defer></script>");
    }
}
