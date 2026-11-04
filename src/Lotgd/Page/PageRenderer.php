<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\TwigTemplate;

/**
 * Turns a PageState into the complete HTML document.
 *
 * Pure with respect to the game: it reads nothing but the state and runs no
 * hooks, so rendering the same state twice yields the same markup and has no
 * further effect.
 */
final class PageRenderer
{
    public static function render(PageState $state): string
    {
        if ($state->twig) {
            return TwigTemplate::render($state->template, $state->templateVars);
        }

        return $state->header . $state->content . $state->footer;
    }
}
