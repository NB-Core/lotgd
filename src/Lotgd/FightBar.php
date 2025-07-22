<?php

declare(strict_types=1);

namespace Lotgd;

class FightBar
{
    private $bg;
    private $red;
    private $green;
    private $yellow;
    private $orange;
    private $grey;
    private $full;
    private $med;
    private $critical;
    private $length;
    private $height;

    public function __construct()
    {
        $this->bg = '#000099';
        $this->red = '#FF0000';
        $this->green = '#00DD00';
        $this->yellow = '#FDF700';
        $this->orange = '#FF8000';
        $this->grey = '#827B84';

        $this->full = 0.67;
        $this->med = 0.47;
        $this->critical = 0.3;

        $this->length = 50;
        $this->height = 10;
    }

    /**
     * Render a small colored bar representing current health/points.
     */
    public function getBar(int $current, int $max): string
    {
        $totalwidth = $this->length;
        if ($max == 0) {
            return '';
        }
        $scale = $current / $max;
        $length = round($scale * ($totalwidth));
        if ($scale > $this->full) {
            $fg = $this->green;
        } elseif ($scale > $this->med) {
            $fg = $this->orange;
        } elseif ($scale > $this->critical) {
            $fg = $this->yellow;
        } elseif ($current <= 0) {
            $fg = $this->grey;
        } else {
            $fg = $this->red;
        }
        $bar = "<div style='display: block;background-color:" . $this->grey . "; width: " . $this->length . "px;height: " . $this->height . "px;'>";
        $bar .= "<div style='background-color:" . $fg . "; width: " . $length . "px;height: " . $this->height . "px;'>";
        $bar .= "</div></div>";
        return $bar;
    }
}
