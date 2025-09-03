<?php

declare(strict_types=1);

namespace Lotgd\Modules {
    class HookHandler
    {
        public static array $received = [];
        public static int $calls = 0;

        public static function massPrepare(array $hookNames): bool
        {
            self::$calls++;
            self::$received[] = $hookNames;
            return true;
        }
    }
}

namespace {
    function mass_module_prepare(array $hooknames): bool
    {
        if ([] === $hooknames) {
            return true;
        }

        return \Lotgd\Modules\HookHandler::massPrepare($hooknames);
    }
}
