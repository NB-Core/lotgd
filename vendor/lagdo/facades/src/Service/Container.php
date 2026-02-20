<?php

namespace Lagdo\Facades\Service;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * A container that acts as a wrapper for the already defined
 * container, while also returning the services defined here.
 */
class Container implements ContainerInterface
{
    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param array $loggerOptions
     * @param ContainerInterface|null $container
     */
    public function __construct(array $loggerOptions,
        private ContainerInterface|null $container)
    {
        $this->logger = new Logger(...$loggerOptions);
    }

    /**
     * @inheritDoc
     */
    public function get(string $id)
    {
        return $id === LoggerInterface::class || $id === Logger::class ?
            $this->logger : $this->container->get($id);
    }

    /**
     * @inheritDoc
     */
    public function has(string $id): bool
    {
        return $id === LoggerInterface::class || $id === Logger::class ?
            true : $this->container->has($id);
    }
}
