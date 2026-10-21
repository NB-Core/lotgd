<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\TwigTemplate;

/**
 * Popup pages shown inside the page (docs/ReactiveCore.md, Phase 6b).
 *
 * A popup page -- one that ends with Footer::popupFooter(): mail, the MOTD --
 * opens in its own browser window by default. On a skin that declares
 * `"overlay": true` in its config.json, the links to it carry
 * data-lotgd-overlay and the overlay client (assets/js/lotgd.overlay.js)
 * fetches the page with the header X-Lotgd-Overlay: 1 and shows it in a
 * dialog. The popup footer then answers the content alone, with the title in
 * a header. Links and forms inside the dialog stay in it.
 *
 * Nothing changes for the server otherwise: the page runs exactly as it does
 * in a window (mail and MOTD override forced navigation, so the page behind
 * the dialog keeps its links), and the onclick that opens a window stays on
 * the link for a browser without the client.
 */
final class Overlay
{
    public const REQUEST_HEADER = 'HTTP_X_LOTGD_OVERLAY';
    public const HEADER = 'X-Lotgd-Overlay';
    public const HEADER_TITLE = 'X-Lotgd-Overlay-Title';
    public const HEADER_CLOSE = 'X-Lotgd-Overlay-Close';

    private static bool $close = false;

    /**
     * Whether this request comes from the overlay client.
     */
    public static function isRequested(): bool
    {
        return ($_SERVER[self::REQUEST_HEADER] ?? '') === '1';
    }

    /**
     * Whether the active skin shows popup pages as an overlay.
     */
    public static function skinSupports(): bool
    {
        return TwigTemplate::isActive() && PartialMode::skinDeclares('overlay');
    }

    /**
     * The attribute a link to a popup page carries, or '' where popups stay
     * windows.
     */
    public static function attribute(): string
    {
        return self::skinSupports() ? ' data-lotgd-overlay="1"' : '';
    }

    /**
     * The page is done with: the client closes the dialog after showing the
     * answer ("Send and Close" in mail).
     */
    public static function close(): void
    {
        self::$close = true;
    }

    /**
     * For tests.
     */
    public static function resetState(): void
    {
        self::$close = false;
    }

    /**
     * A page stored earlier and shown again (badnav.php): the MOTD it opened
     * by itself is not opened again once it has been read.
     */
    public static function storedPage(string $html, bool $motdUnseen): string
    {
        if ($motdUnseen) {
            return $html;
        }

        return (string) preg_replace('/<meta name="lotgd-overlay-open" content="[^"]*">/', '', $html);
    }

    /**
     * The answer to an overlay request: the content region, the title in a
     * header (percent-encoded, so any title is a valid header value).
     *
     * @return array{body:string,headers:array<string,string>}
     */
    public static function render(PageState $state): array
    {
        $headers = [
            self::HEADER => '1',
            self::HEADER_TITLE => rawurlencode(strip_tags($state->title())),
        ];
        if (self::$close) {
            $headers[self::HEADER_CLOSE] = '1';
        }

        return ['body' => $state->content, 'headers' => $headers];
    }
}
