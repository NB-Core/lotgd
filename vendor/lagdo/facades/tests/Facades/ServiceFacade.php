<?php

namespace Lagdo\Facades\Tests\Facades;

use Lagdo\Facades\AbstractFacade;
use Lagdo\Facades\Tests\Service\ServiceInterface;

/**
 * @extends AbstractFacade<ServiceInterface>
 */
class ServiceFacade extends AbstractFacade
{
    /**
     * @inheritdoc
     */
    protected static function getServiceIdentifier(): string
    {
        return ServiceInterface::class;
    }
}
