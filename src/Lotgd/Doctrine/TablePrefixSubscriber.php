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

        $primaryTable = $classMetadata->table;
        $primaryTable['name'] = $this->prefix . $classMetadata->getTableName();
        $classMetadata->setPrimaryTable($primaryTable);

        foreach ($classMetadata->getAssociationMappings() as $fieldName => $mapping) {
            if (is_array($mapping) && isset($mapping['joinTable']['name'])) {
                $mapping['joinTable']['name'] = $this->prefix . $mapping['joinTable']['name'];
                $classMetadata->associationMappings[$fieldName] = $mapping;
                continue;
            }

            if (is_object($mapping) && property_exists($mapping, 'joinTable') && $mapping->joinTable !== null) {
                $joinTable = $mapping->joinTable;
                if (property_exists($joinTable, 'name') && is_string($joinTable->name)) {
                    $joinTable->name = $this->prefix . $joinTable->name;
                }
            }
        }
    }
}
