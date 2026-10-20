<?php

declare(strict_types=1);

namespace Lotgd\Page;

/**
 * Turns a PageState into the regions a partial response swaps in.
 *
 * The body is the content region, which the client swaps into
 * [data-lotgd-region="content"]. Every other region travels as an
 * out-of-band element whose selector matches all elements of that region,
 * because a skin may show one region twice (Aurora renders the navigation in
 * the sidebar and again in the mobile menu). The header slots are addressed
 * one by one, since a skin places them apart.
 *
 * Like PageRenderer it reads nothing but the state and runs no hooks.
 */
final class PartialRenderer
{
    public const HEADER_PARTIAL = 'X-Lotgd-Partial';
    public const HEADER_REVISION = 'X-Lotgd-Page-Revision';

    /**
     * @return array{body:string,headers:array<string,string>}
     */
    public static function render(PageState $state, int $pageRevision): array
    {
        $body = $state->content;
        $body .= '<title>' . htmlspecialchars($state->title(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</title>';
        $body .= self::outOfBand('[data-lotgd-region="nav"]', $state->region('nav'));
        $body .= self::outOfBand('[data-lotgd-region="stats"]', $state->region('stats'));
        foreach (PageState::REGIONS['header'] as $slot) {
            $body .= self::outOfBand(
                '[data-lotgd-region="header"][data-lotgd-slot="' . $slot . '"]',
                (string) ($state->templateVars[$slot] ?? '')
            );
        }

        return [
            'body' => $body,
            'headers' => [
                self::HEADER_PARTIAL => '1',
                self::HEADER_REVISION => (string) $pageRevision,
            ],
        ];
    }

    /**
     * Whether the page can be swapped in as regions.
     *
     * A page whose content carries a <script> element is not: the client does
     * not run scripts it swaps in (that would start timers and handlers once
     * per swap), so such a page is shown as a full page instead.
     */
    public static function canRender(PageState $state): bool
    {
        return !$state->isPopup() && stripos($state->content, '<script') === false;
    }

    private static function outOfBand(string $selector, string $markup): string
    {
        return '<div hx-swap-oob="innerHTML:' . htmlspecialchars($selector, ENT_QUOTES, 'UTF-8') . '">'
            . $markup
            . '</div>';
    }
}
