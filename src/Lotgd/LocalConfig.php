<?php
declare(strict_types=1);
namespace Lotgd;

class LocalConfig
{
    public static function apply(): void
    {
        ini_set('memory_limit','64M');
        ini_set('max_execution_time','90');
    }
}
