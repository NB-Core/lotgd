<?php
namespace Lotgd\Installer;

use RuntimeException;

class InstallerLogger
{
    public static function log(string $message): void
    {
        $logDir = __DIR__ . '/../../errors';
        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $logDir));
            }
        }
        $logFile = $logDir . '/install.log';
        $date = date('Y-m-d H:i:s');
        $entry = sprintf("[%s] %s\n", $date, $message);
        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
