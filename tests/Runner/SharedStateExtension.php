<?php

declare(strict_types=1);

namespace Lotgd\Tests\Runner;

use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

/**
 * Registers the per-test reset of the suite's process-global state.
 *
 * Wiring this as an extension rather than a shared base class means no test can
 * opt out by forgetting to call parent::setUp(), and no existing test file had
 * to change to gain the isolation.
 */
final class SharedStateExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new ResetSharedState());
    }
}
