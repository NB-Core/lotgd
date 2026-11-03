<?php

declare(strict_types=1);

namespace Lotgd\Page;

/**
 * The page a login returns the player to (`accounts.restorepage`).
 *
 * login.php restores it with a plain GET. A form's target is not a page a
 * GET can show: `configuration.php?op=save` and the other core operations
 * that need a posted form token answer a GET with 400, and other form
 * targets show something the form never meant. So a POST leaves the page
 * that showed the form in place, and only a request a GET can repeat
 * becomes the page to return to.
 */
final class RestorePage
{
    /**
     * Note $uri as the page to return to, unless this request posted a form.
     *
     * @param array<string,mixed> $user The session user, changed in place.
     */
    public static function remember(array &$user, string $uri): void
    {
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
            return;
        }
        $user['restorepage'] = $uri;
    }
}
