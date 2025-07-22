<?php

declare(strict_types=1);

namespace Lotgd\Nav;

/**
 * Represents a sub headline grouping navigation items.
 */
class NavigationSubSection
{
    public string|array $headline;
    private array $items = [];
    public bool $translate;
    public bool $colored = false;

    /**
     * @param string|array $headline Subsection headline text
     * @param bool         $translate When false the text is not translated
     */
    public function __construct($headline, bool $translate = true)
    {
        $this->headline = $headline;
        $this->translate = $translate;
    }

    /**
     * Add an item to the subsection.
     */
    public function addItem(NavigationItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @return NavigationItem[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * @param NavigationItem[] $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
    }
}
