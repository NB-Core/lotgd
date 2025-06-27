<?php
namespace Lotgd\Page;

class CharStats {
    protected $info = [];
    protected $lastLabel = '';

    public function wipe(): void {
        $this->info = [];
        $this->lastLabel = '';
    }

    public function add(string $label, $value = false): void {
        if ($value === false) {
            if (!isset($this->info[$label])) {
                $this->info[$label] = [];
            }
            $this->lastLabel = $label;
        } else {
            if ($this->lastLabel === '') {
                $this->lastLabel = 'Other Info';
                $this->add($this->lastLabel);
            }
            $this->info[$this->lastLabel][$label] = $value;
        }
    }

    public function get(string $cat, string $label) {
        return $this->info[$cat][$label] ?? null;
    }

    public function set(string $cat, string $label, $val): void {
        if (!isset($this->info[$cat][$label])) {
            $old = $this->lastLabel;
            $this->add($cat);
            $this->add($label, $val);
            $this->lastLabel = $old;
        } else {
            $this->info[$cat][$label] = $val;
        }
    }

    public function render($buffs): string {
        $str = templatereplace('statstart');
        foreach ($this->info as $label => $section) {
            if (count($section)) {
                $arr = ['title' => translate_inline($label)];
                $sectionhead = templatereplace('stathead', $arr);
                foreach ($section as $name => $val) {
                    if ($name == $label) {
                        $a2 = ['title' => translate_inline("`0$name"), 'value' => "`^$val`0"];
                        $str .= templatereplace('statbuff', $a2);
                    } else {
                        $a2 = ['title' => translate_inline("`&$name`0"), 'value' => "`^$val`0"];
                        $str .= $sectionhead . templatereplace('statrow', $a2);
                        $sectionhead = '';
                    }
                }
            }
        }
        $str .= templatereplace('statbuff', ['title' => translate_inline('`0Buffs'), 'value' => $buffs]);
        $str .= templatereplace('statend');
        return appoencode($str, true);
    }

    public function value(string $section, string $title) {
        return $this->info[$section][$title] ?? '';
    }
}
