<?php

declare(strict_types=1);

namespace Lotgd\Nav;

/**
 * Group of navigation items under a headline.
 */
class NavigationSection
{
    public string|array $headline;
    private array $items = [];
    /** @var NavigationSubSection[] */
    private array $subSections = [];
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
     * Add a subsection below this headline.
     */
    public function addSubSection(NavigationSubSection $sub): void
    {
        $this->subSections[] = $sub;
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
     * Get the subsections within this section.
     *
     * @return NavigationSubSection[]
     */
    public function getSubSections(): array
    {
        return $this->subSections;
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

    /**
     * Replace the list of subsections in the section.
     *
     * @param NavigationSubSection[] $subs
     */
    public function setSubSections(array $subs): void
    {
        $this->subSections = $subs;
    }
}
