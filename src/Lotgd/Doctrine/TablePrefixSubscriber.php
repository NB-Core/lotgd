<?php

declare(strict_types=1);

namespace Lotgd\Doctrine;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * Adds a table prefix to Doctrine metadata.
 */
class TablePrefixSubscriber implements EventSubscriber
{
    private string $prefix;

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }

    /**
     * @return array<int, string>
     */
    public function getSubscribedEvents(): array
    {
        return ['loadClassMetadata'];
    }

    public function loadClassMetadata(LoadClassMetadataEventArgs $eventArgs): void
    {
        if ($this->prefix === '') {
            return;
        }

        $classMetadata = $eventArgs->getClassMetadata();

        $classMetadata->table['name'] = $this->prefix . $classMetadata->table['name'];

        foreach ($classMetadata->associationMappings as $fieldName => $mapping) {
            if (isset($mapping['joinTable']['name'])) {
                $classMetadata->associationMappings[$fieldName]['joinTable']['name'] =
                    $this->prefix . $mapping['joinTable']['name'];
            }
        }
    }
}
