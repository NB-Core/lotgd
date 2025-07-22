<?php

declare(strict_types=1);

/**
 * Represents a single navigation link.
 */

namespace Lotgd\Nav;

use Lotgd\Nav;

class NavigationItem
{
    public string|array $text;
    public string $link;
    public bool $priv;
    public bool $popup;
    public string $popupSize;
    public bool $translate;

    /**
     * Create a new navigation item.
     *
     * @param string|array $text      Link label or template array
     * @param string       $link      URL for the link
     * @param bool         $priv      Passed through to appoencode()
     * @param bool         $popup     Whether to open the link in a popup
     * @param string       $popupSize Popup dimensions when $popup is true
     * @param bool         $translate When false, skip translation
     */
    public function __construct($text, $link, bool $priv = false, bool $popup = false, string $popupSize = '500x300', bool $translate = true)
    {
        $this->text = $text;
        $this->link = $link;
        $this->priv = $priv;
        $this->popup = $popup;
        $this->popupSize = $popupSize;
        $this->translate = $translate;
    }

    /**
     * Render the navigation item as HTML.
     */
    public function render(): string
    {
        $output = Nav::privateAddNav(
            $this->text,
            $this->link,
            $this->priv,
            $this->popup,
            $this->popupSize
        );

        return is_string($output) ? $output : '';
    }
}
