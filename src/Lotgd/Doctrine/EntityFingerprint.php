<?php

declare(strict_types=1);

namespace Lotgd\Doctrine;

/**
 * A short fingerprint of the entity files -- name, size and modification time
 * of each -- for the metadata cache namespace.
 */
final class EntityFingerprint
{
    public static function of(string $entityDir): string
    {
        $state = '';
        foreach ((array) glob($entityDir . '/*.php') as $file) {
            $file = (string) $file;
            $state .= basename($file) . ':' . (int) @filesize($file) . ':' . (int) @filemtime($file) . ';';
        }

        return substr(hash('sha256', $state), 0, 12);
    }
}
