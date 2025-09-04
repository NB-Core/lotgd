<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Translator;
use Lotgd\Template;
use Lotgd\Output;

class CharStats
{
    private array $stats = [];

    /**
     * Reset all stored stats.
     */
    public function clear(): void
    {
        $this->stats = [];
    }

    /**
     * Add a single stat entry.
     *
     * @param string     $section Section name
     * @param string     $label   Stat label
     * @param mixed|null $value   Value to display
     */
    public function addStat(string $section, string $label, mixed $value = null): void
    {
        if (!isset($this->stats[$section])) {
            $this->stats[$section] = [];
        }
        if ($label !== '') {
            $this->stats[$section][$label] = $value;
        }
    }

    /**
     * Replace or create a stat entry.
     */
    public function setStat(string $section, string $label, mixed $value): void
    {
        $this->addStat($section, $label, $value);
    }

    /**
     * Retrieve a previously set stat value.
     */
    public function getStat(string $section, string $label): mixed
    {
        return $this->stats[$section][$label] ?? null;
    }

    /**
     * Render the stat table to HTML.
     */
    public function render(string $buffs): string
    {
        $output = Output::getInstance();
        $charstat_str = Template::templateReplace('statstart');
        foreach ($this->stats as $label => $section) {
            if (count($section)) {
                $arr = ['title' => Translator::translateInline($label)];
                $sectionhead = Template::templateReplace('stathead', $arr);
                foreach ($section as $name => $val) {
                    if ($name == $label) {
                        $a2 = ['title' => Translator::translateInline("`0$name"), 'value' => "`^$val`0"];
                        $charstat_str .= Template::templateReplace('statbuff', $a2);
                    } else {
                        $a2 = ['title' => Translator::translateInline("`&$name`0"), 'value' => "`^$val`0"];
                        $charstat_str .= $sectionhead . Template::templateReplace('statrow', $a2);
                        $sectionhead = '';
                    }
                }
            }
        }
        $charstat_str .= Template::templateReplace('statbuff', ['title' => Translator::translateInline('`0Buffs'), 'value' => $buffs]);
        $charstat_str .= Template::templateReplace('statend');
        return $output->appoencode($charstat_str, true);
    }
}
