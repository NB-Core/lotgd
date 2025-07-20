<?php
declare(strict_types=1);

namespace Lotgd\Nav;

/**
 * Group of navigation items under a headline.
 */
class NavigationSection
{
    public $headline;
    private array $items = [];
    public bool $collapse;
    public bool $colored = false;

    /**
     * Create a new navigation section.
     *
     * @param string|array $headline Section headline or translation array
     * @param bool         $collapse Allow the section to collapse
     * @param bool         $colored  Whether the headline contains colour codes
     */
    public function __construct($headline, bool $collapse = true, bool $colored = false)
    {
        $this->headline = $headline;
        $this->collapse = $collapse;
        $this->colored = $colored;
    }

    /**
     * Add an item to the section.
     */
    public function addItem(NavigationItem $item): void
    {
        $this->items[] = $item;
    }

    /**
     * Get the items within the section.
     *
     * @return NavigationItem[]
     */
    public function getItems(): array
    {
        return $this->items;
    }

    /**
     * Replace the list of items in the section.
     *
     * @param NavigationItem[] $items
     */
    public function setItems(array $items): void
    {
        $this->items = $items;
    }
}
