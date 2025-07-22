<?php

declare(strict_types=1);

namespace Lotgd;

/**
 * Helper methods for safe serialization handling.
 */
class Serialization
{
    /**
     * Safely unserialize data. Returns original input on error.
     *
     * @param mixed $data Serialized string or other value
     *
     * @return mixed Unserialized data or original input
     */
    public static function safeUnserialize(mixed $data): mixed
    {
        if (!is_string($data) || trim($data) === '') {
            return $data;
        }
        $data = trim($data);
        if (self::isValidSerialized($data)) {
            set_error_handler(function () {
                return true;
            });
            try {
                $result = unserialize($data, ['allowed_classes' => false]);
                restore_error_handler();
                return $result !== false || $data === 'b:0;' ? $result : $data;
            } catch (\Throwable $e) {
                restore_error_handler();
                return $data;
            }
        }
        return $data;
    }

    /**
     * Check if a string looks like serialized data.
     *
     * @param mixed $data Potential serialized value
     *
     * @return bool True if data appears serialized
     */
    public static function isValidSerialized(mixed $data): bool
    {
        if (!is_string($data) || trim($data) === '') {
            return false;
        }
        $data = trim($data);
        if ($data === 'N;' || $data === 'b:0;') {
            return true;
        }
        if (!preg_match('/^([adObis]):/', $data)) {
            return false;
        }
        set_error_handler(function () {
            return true;
        });
        $result = @unserialize($data, ['allowed_classes' => false]);
        restore_error_handler();
        return $result !== false || $data === 'b:0;';
    }
}
