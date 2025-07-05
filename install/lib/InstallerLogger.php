<?php
namespace Lotgd\Installer;

use RuntimeException;

class InstallerLogger
{
    /**
     * Return the install log file path.
     */
    public static function getLogFilePath(): string
    {
        return __DIR__ . '/../errors/install.log';
    }

    public static function log(string $message): bool
    {
        $logDir = dirname(self::getLogFilePath());
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                return false;
            }
        }

        $date  = date('Y-m-d H:i:s');
        $entry = sprintf("[%s] %s\n", $date, $message);

        try {
            $written = file_put_contents(self::getLogFilePath(), $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $th) {
            return false;
        }

        if ($written === false) {
            return false;
        }

        return true;
    }
}
