<?php
namespace Lotgd;

class CharStats
{
    private array $stats = [];

    public function clear(): void
    {
        $this->stats = [];
    }

    public function addStat(string $section, string $label, mixed $value = null): void
    {
        if (!isset($this->stats[$section])) {
            $this->stats[$section] = [];
        }
        if ($label !== '') {
            $this->stats[$section][$label] = $value;
        }
    }

    public function setStat(string $section, string $label, mixed $value): void
    {
        $this->addStat($section, $label, $value);
    }

    public function getStat(string $section, string $label): mixed
    {
        return $this->stats[$section][$label] ?? null;
    }

    public function render(array $buffs): string
    {
        $charstat_str = templatereplace('statstart');
        foreach ($this->stats as $label => $section) {
            if (count($section)) {
                $arr = ['title' => translate_inline($label)];
                $sectionhead = templatereplace('stathead', $arr);
                foreach ($section as $name => $val) {
                    if ($name == $label) {
                        $a2 = ['title' => translate_inline("`0$name"), 'value' => "`^$val`0"];
                        $charstat_str .= templatereplace('statbuff', $a2);
                    } else {
                        $a2 = ['title' => translate_inline("`&$name`0"), 'value' => "`^$val`0"];
                        $charstat_str .= $sectionhead . templatereplace('statrow', $a2);
                        $sectionhead = '';
                    }
                }
            }
        }
        $charstat_str .= templatereplace('statbuff', ['title' => translate_inline('`0Buffs'), 'value' => $buffs]);
        $charstat_str .= templatereplace('statend');
        return appoencode($charstat_str, true);
    }
}
