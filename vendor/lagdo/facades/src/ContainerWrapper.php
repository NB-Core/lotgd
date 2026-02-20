<?php

namespace Lagdo\Facades;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

final class ContainerWrapper
{
    /**
     * @var ContainerInterface|null
     */
    private static ContainerInterface|null $container = null;

    /**
     * @param ContainerInterface $container
     * @param bool $overwrite
     *
     * @return void
     */
    public static function setContainer(ContainerInterface $container, bool $overwrite = true): void
    {
        if($overwrite || self::$container === null)
        {
            self::$container = $container;
        }
    }

    /**
     * @return ContainerInterface|null
     */
    public static function getContainer(): ContainerInterface|null
    {
        return self::$container;
    }

    /**
     * Get a service using the container.
     *
     * @param string $serviceId
     *
     * @return mixed
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public static function getFacadeService(string $serviceId): mixed
    {
        return self::$container->get($serviceId);
    }

    /**
     * Register the local services in a container wrapper.
     *
     * @param array $loggerOptions
     *
     * @return void
     */
    public static function registerLocalServices(array $loggerOptions): void
    {
        self::$container = new Service\Container($loggerOptions, self::$container);
    }
}
