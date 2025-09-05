<?php

declare(strict_types=1);

namespace Lotgd\Page;

use Lotgd\CharStats as CoreCharStats;
use Lotgd\PageParts;
use Lotgd\Translator;
use Lotgd\PlayerFunctions;
use Lotgd\Buffs;
use Lotgd\DateTime;

class CharStats
{
    private static ?CoreCharStats $charstats = null;
    private static string $lastCharstatLabel = '';

    public static function wipe(): void
    {
        self::$charstats = new CoreCharStats();
        self::$lastCharstatLabel = '';
    }

    public static function add(string $label, mixed $value = null): void
    {
        if ($value === null) {
            self::$lastCharstatLabel = $label;
        } else {
            if (self::$lastCharstatLabel === '') {
                self::$lastCharstatLabel = 'Other Info';
            }
            self::$charstats?->addStat(self::$lastCharstatLabel, $label, $value);
        }
    }

    public static function get(string $cat, string $label)
    {
        return self::$charstats?->getStat($cat, $label);
    }

    public static function set(string $cat, string $label, mixed $val): void
    {
        self::$charstats?->setStat($cat, $label, $val);
    }

    public static function render(string $buffs): string
    {
        return self::$charstats?->render($buffs) ?? '';
    }

    public static function value(string $section, string $title)
    {
        return self::$charstats?->getStat($section, $title) ?? '';
    }

    public static function display(): string
    {
        return PageParts::charStats();
    }
}
