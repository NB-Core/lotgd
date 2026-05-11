<?php

namespace Jaxon\Storage;

/**
 * Get the storage manager
 *
 * @return StorageManager
 */
function storage(): StorageManager
{
    static $xStorageManager = null;
    return $xStorageManager ??= new StorageManager();
}
