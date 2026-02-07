<?php

namespace Lagdo\Facades;

use Psr\Log\LoggerInterface;

/**
 * @extends AbstractFacade<LoggerInterface>
 */
final class Logger extends AbstractFacade
{
    use ServiceInstance;

    /**
     * @inheritDoc
     */
    protected static function getServiceIdentifier(): string
    {
        return LoggerInterface::class;
    }
}
