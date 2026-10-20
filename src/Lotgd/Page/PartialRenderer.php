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
    public const REQUEST_HEAD_SERVER_KEY = 'HTTP_X_LOTGD_HEAD';

    /** Template variables that end up in <head>. */
    private const HEAD_VARIABLES = ['headscript_pre', 'headscript_mid', 'headscript', 'script'];

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
     * Not when any region carries a <script> element: the client does not
     * run scripts it swaps in (that would start timers and handlers once per
     * swap). Not when the page wants a different <head> than the one the
     * client is showing -- a module that adds a stylesheet, a script or head
     * markup for this page -- because a partial answer has no head. Such a
     * page is shown as a full page instead.
     *
     * @param string|null $clientHeadSignature The signature the client sent, from the page it shows.
     */
    public static function canRender(PageState $state, ?string $clientHeadSignature): bool
    {
        if ($state->isPopup()) {
            return false;
        }

        if ($clientHeadSignature === null || !hash_equals($state->headSignature, $clientHeadSignature)) {
            return false;
        }

        foreach (array_keys(PageState::REGIONS) as $region) {
            if (stripos($state->region($region), '<script') !== false) {
                return false;
            }
        }

        return true;
    }

    /**
     * A short hash of what the page puts in <head>, so the client can tell
     * the server which head it is showing.
     *
     * Cache busters, the canonical link (it names the page) and the partial
     * client's own meta tags are left out: they change from page to page
     * without the head loading anything else.
     *
     * @param array<string,mixed> $templateVars
     */
    public static function headSignature(array $templateVars): string
    {
        $head = '';
        foreach (self::HEAD_VARIABLES as $variable) {
            $head .= (string) ($templateVars[$variable] ?? '');
        }
        $head = (string) preg_replace('/<meta name="lotgd-[a-z-]+" content="[^"]*">/', '', $head);
        $head = (string) preg_replace('/<link rel="canonical" href="[^"]*" \/>/', '', $head);
        $head = (string) preg_replace('/\?v=[0-9]+/', '', $head);

        return substr(hash('sha256', $head), 0, 16);
    }

    private static function outOfBand(string $selector, string $markup): string
    {
        return '<div hx-swap-oob="innerHTML:' . htmlspecialchars($selector, ENT_QUOTES, 'UTF-8') . '">'
            . $markup
            . '</div>';
    }
}
