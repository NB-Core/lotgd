<?php

declare(strict_types=1);

namespace Lotgd\Installer;

class InstallerLogger
{
    /**
     * Return the install log file path.
     */
    public static function getLogFilePath(): string
    {
        $customDir = getenv('LOTGD_DATA_DIR');
        if ($customDir) {
            return rtrim($customDir, '/') . '/install.log';
        }

        $stateDir = getenv('LOTGD_STATE_PATH');
        if ($stateDir !== false && trim($stateDir) !== '') {
            return rtrim($stateDir, '/\\') . '/logs/install.log';
        }

        $defaultDir = __DIR__ . '/../errors';
        if (
            (is_dir($defaultDir) && is_writable($defaultDir))
            || (!is_dir($defaultDir) && is_writable(dirname($defaultDir)))
        ) {
            return $defaultDir . '/install.log';
        }

        return sys_get_temp_dir() . '/lotgd_install/install.log';
    }

    /**
     * Append a message to the installer log file.
     *
     * @param string $message Message to write to the log.
     *
     * @return bool True on success, false otherwise.
     */
    public static function log(string $message): bool
    {
        $logFile = self::getLogFilePath();
        $logDir  = dirname($logFile);

        if (!is_dir($logDir)) {
            $parent = dirname($logDir);
            if (!is_writable($parent)) {
                return false;
            }

            if (!mkdir($logDir, 0750, true) && !is_dir($logDir)) {
                error_log(sprintf('Unable to create installer log directory: %s', $logDir));
                return false;
            }
        }

        if (!is_writable($logDir)) {
            error_log(sprintf('Installer log directory is not writable: %s', $logDir));
            return false;
        }

        $date  = date('Y-m-d H:i:s');
        $entry = sprintf("[%s] %s\n", $date, $message);

        try {
            $written = file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $th) {
            error_log(sprintf('Unable to write installer log %s: %s', $logFile, $th->getMessage()));
            return false;
        }

        if ($written === false) {
            error_log(sprintf('Unable to write installer log: %s', $logFile));
            return false;
        }

        if (function_exists('output')) {
            output("`n`^See %s for a detailed error log.`n", $logFile);
        }

        return true;
    }
}
