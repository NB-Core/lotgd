<?php

namespace Lagdo\Facades\Tests\Facades\Instance;

use Lagdo\Facades\ServiceInstance;
use Lagdo\Facades\Tests\Service\ServiceInterface;

/**
 * @extends AbstractFacade<ServiceInterface>
 */
class ServiceFacade extends AbstractFacade
{
    use ServiceInstance;

    /**
     * @inheritdoc
     */
    protected static function getServiceIdentifier(): string
    {
        return ServiceInterface::class;
    }
}
