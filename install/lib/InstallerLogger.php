<?php
namespace Lotgd\Installer;

class InstallerLogger
{
    public static function log(string $message): void
    {
        $logDir = __DIR__ . '/../../errors';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $logFile = $logDir . '/install.log';
        $date = date('Y-m-d H:i:s');
        $entry = sprintf("[%s] %s\n", $date, $message);
        error_log($entry, 3, $logFile);
    }
}
