<?php

declare(strict_types=1);

namespace Lotgd\Page;

/**
 * Everything a finished page consists of, assembled once by the footer.
 *
 * Footer::buildPageState() and Footer::buildPopupState() run every side
 * effect a page end has -- registering the built navigation in allowednavs,
 * the footer and stats hooks, the MOTD check -- while they fill this object.
 * A renderer only turns it into markup and must not run hooks of its own, so
 * a second renderer (partial regions, see docs/ReactiveCore.md) can be added
 * without the page doing that work twice or differently.
 *
 * The typed fields are the parts the reactive core addresses by name. The
 * Twig context is kept whole in $templateVars, because skins may use any
 * variable PageParts::$twigVars ever carried.
 */
final class PageState
{
    public const TEMPLATE_PAGE = 'page.twig';
    public const TEMPLATE_POPUP = 'popup.twig';

    /**
     * Regions a Twig skin can mark with data-lotgd-region, and the template
     * variables that fill each of them.
     *
     * @var array<string,list<string>>
     */
    public const REGIONS = [
        'content' => ['content'],
        'nav' => ['nav'],
        'stats' => ['stats'],
        'header' => ['motd', 'mail', 'petition', 'petitiondisplay'],
    ];

    /**
     * @param string                $template     Twig view, one of the TEMPLATE_* constants.
     * @param bool                  $twig         Whether a Twig skin renders the page; false
     *                                            means a legacy .htm template.
     * @param string                $header       Template header with its tokens replaced.
     * @param string                $footer       Template footer with its tokens replaced.
     * @param string                $content      The page body as it is shown.
     * @param string                $nav          Built navigation markup ('' for popups).
     * @param string                $stats        Character stats markup ('' for popups).
     * @param array<string,string>  $quickKeys    Access key => JavaScript action.
     * @param array<string,mixed>   $templateVars Complete Twig context.
     * @param string                $headSignature Hash of what the page puts in <head>; see
     *                                            PartialRenderer::headSignature().
     * @param array<string,int|string> $asyncState The values the AJAX polling script starts
     *                                            from (comment section, last ids); empty
     *                                            when the player has AJAX off.
     * @param array<string,int>       $regionVersions The version of each realtime region as
     *                                            this page shows it; empty without realtime.
     */
    public function __construct(
        public readonly string $template,
        public readonly bool $twig,
        public readonly string $header,
        public readonly string $footer,
        public readonly string $content,
        public readonly string $nav = '',
        public readonly string $stats = '',
        public readonly array $quickKeys = [],
        public readonly array $templateVars = [],
        public readonly string $headSignature = '',
        public readonly array $asyncState = [],
        public readonly array $regionVersions = [],
    ) {
    }

    public function isPopup(): bool
    {
        return $this->template === self::TEMPLATE_POPUP;
    }

    public function title(): string
    {
        return (string) ($this->templateVars['title'] ?? '');
    }

    /**
     * The markup of one region, joined from its template variables.
     *
     * @throws \InvalidArgumentException For a name outside self::REGIONS.
     */
    public function region(string $name): string
    {
        if (!isset(self::REGIONS[$name])) {
            throw new \InvalidArgumentException(sprintf('Unknown page region "%s".', $name));
        }

        $markup = '';
        foreach (self::REGIONS[$name] as $variable) {
            $markup .= match ($variable) {
                'content' => $this->content,
                'nav' => $this->nav,
                'stats' => $this->stats,
                default => (string) ($this->templateVars[$variable] ?? ''),
            };
        }

        return $markup;
    }
}
