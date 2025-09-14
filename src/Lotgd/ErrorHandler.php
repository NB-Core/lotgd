<?php

declare(strict_types=1);

/**
 * Centralized error handling utilities.
 */

namespace Lotgd;

use Lotgd\Backtrace;
use Lotgd\Sanitize;
use Lotgd\Settings;
use Lotgd\DataCache;
use Lotgd\Translator;
use Lotgd\Output;

class ErrorHandler
{
    /**
     * Render a fatal error message in a simple HTML page.
     *
     * @param string $message   Error text to display
     * @param string $file      Originating filename
     * @param int    $line      Line number of the error
     * @param string $backtrace HTML representation of the stack trace
     */
    public static function renderError(string $message, string $file, int $line, string $backtrace): void
    {
        if ('cli' !== PHP_SAPI && !headers_sent()) {
            http_response_code(500);
        }
        echo "<!DOCTYPE html>\n";
        echo "<html lang='en'><head>\n";
        echo "<meta charset='UTF-8'>\n";
        echo "<title>Application Error</title>\n";
        echo "<style>body{background:#000;color:#fff;font-family:sans-serif;padding:20px;}a{color:#fff;}pre{background:#111;padding:10px;overflow:auto;}</style>\n";
        echo "</head><body>\n";
        echo "<h1>Application Error</h1>\n";
        echo sprintf('<p>%s</p>', htmlentities($message, ENT_COMPAT));
        echo sprintf('<p>in <b>%s</b> at <b>%s</b></p>', htmlentities($file, ENT_COMPAT), $line);
        echo $backtrace;
        echo "<p>If the problem persists, please <a href='/petition.php'>submit a petition</a>.</p>\n";
        echo "</body></html>";
    }

    /**
     * Default PHP error handler that displays debug output and sends notifications.
     *
     * @param int    $errno     PHP error level constant
     * @param string $errstr    Error message
     * @param string $errfile   File the error originated from
     * @param int    $errline   Line in the file the error originated from
     * @return void
     */
    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): void
    {
        global $session;
        static $inErrorHandler = 0;

        if (! error_reporting()) {
            return; // @ operator used
        }
        ini_set('display_errors', '1');
        $settings = Settings::hasInstance() ? Settings::getInstance() : null;
        $output   = Output::getInstance();
        $inErrorHandler++;
        if ($inErrorHandler > 1) {
            echo "PHP ERROR: \"$errstr\"<br>in <b>$errfile</b> at <b>$errline</b>.  Additionally this occurred while within logd_error_handler().<br>";
            $inErrorHandler--;
            return;
        }
        switch ($errno) {
            case E_NOTICE:
            case E_USER_NOTICE:
                $showNotices = $settings instanceof Settings
                    ? $settings->getSetting('show_notices', 0)
                    : 0;
                if ($showNotices && ($session['user']['superuser'] & SU_SHOW_PHPNOTICE)) {
                    $output->debug("PHP Notice: \"$errstr\"<br>in <b>$errfile</b> at <b>$errline</b>.", true);
                }
                break;
            case E_WARNING:
            case E_USER_WARNING:
                Translator::getInstance()->setSchema('errorhandler');
                $output->debug(sprintf('PHP Warning: "%s" in %s at %s.', $errstr, $errfile, $errline), true);
                Translator::getInstance()->setSchema();
                if (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT) == SU_DEBUG_OUTPUT) {
                    $backtrace = Backtrace::show();
                    $output->rawOutput($backtrace);
                } else {
                    $backtrace = '';
                }
                if ($settings instanceof Settings && ! empty($settings->getSetting('notify_on_warn', 0))) {
                    self::errorNotify($errno, $errstr, $errfile, $errline, $backtrace);
                }
                break;
            case E_ERROR:
            case E_USER_ERROR:
                $backtrace = Backtrace::show();
                self::renderError($errstr, $errfile, $errline, $backtrace);
                if ($settings instanceof Settings && ! empty($settings->getSetting('notify_on_error', 0))) {
                    self::errorNotify($errno, $errstr, $errfile, $errline, $backtrace);
                }
                die();
                break;
        }
        $inErrorHandler--;
    }

    /**
     * Translate a PHP error number into a readable label.
     */
    private static function errorLabel(int $errno): string
    {
        return match ($errno) {
            E_ERROR, E_USER_ERROR => 'Error',
            E_WARNING, E_USER_WARNING => 'Warning',
            E_NOTICE, E_USER_NOTICE => 'Notice',
            default => 'Unknown',
        };
    }

    /**
     * Send an e-mail notification about a PHP error.
     *
     * @param int   $errno     PHP error level
     * @param mixed $errstr    Error message
     * @param string $errfile  File in which the error occurred
     * @param int    $errline  Line number of the error
     * @param string $backtrace HTML stack trace
     * @return void
     */
    public static function errorNotify(int $errno, $errstr, string $errfile, int $errline, string $backtrace): void
    {
        global $session;
        $settings = Settings::hasInstance() ? Settings::getInstance() : null;
        $output   = Output::getInstance();
        if (! $settings instanceof Settings) {
            return;
        }

        $msg = is_string($errstr) ? $errstr : json_encode($errstr);
        if (strlen($msg) <= 0) {
            return;
        }

        $addressList = (string) $settings->getSetting('notify_address', '');
        $sendto = array_filter(array_map('trim', explode(';', $addressList)));
        if (empty($sendto)) {
            $sendto = [$settings->getSetting('gameadminemail', 'postmaster@localhost')];
        }

        $howoften = (int) $settings->getSetting('notify_every', 30);
        $data = DataCache::getInstance()->datacache('error_notify', 86400);
        if (!is_array($data)) {
            $data = ['firstrun' => false, 'errors' => []];
            if (
                $settings->getSetting('usedatacache', 0)
                && !DataCache::getInstance()->updatedatacache('error_notify', $data)
            ) {
                error_log('Unable to write datacache for error_notify');
            }
        } else {
            if (!isset($data['errors']) || !is_array($data['errors'])) {
                $data['errors'] = [];
            }
            if (!array_key_exists('firstrun', $data)) {
                $data['firstrun'] = false;
            }
        }
        $doNotice = false;
        if (!array_key_exists($msg, $data['errors'])) {
            $doNotice = true;
        } elseif (strtotime('now') - ($data['errors'][$msg]) > $howoften * 60) {
            $doNotice = true;
        }
        if (!isset($data['firstrun'])) {
            $data['firstrun'] = false;
        }
        if ($data['firstrun']) {
            $output->debug('First run, not notifying users.', true);
        } else {
            if ($doNotice) {
                $userstr = '';
                if ($session && isset($session['user']['name']) && isset($session['user']['acctid'])) {
                    $userstr = 'Error triggered by user ' . $session['user']['name'] . ' (' . $session['user']['acctid'] . ")\n";
                }
                $label = self::errorLabel($errno);
                $hostname = $_SERVER['HTTP_HOST'] ?? 'CLI execution – hostname unavailable';
                $plain_text = "Host: $hostname\nType: $label\n$userstr$msg in $errfile ($errline)\n" . Sanitize::sanitizeHtml($backtrace);
                $html_text = "<html><body>Host: $hostname<br>Type: $label<br>$msg in $errfile ($errline)<hr>$backtrace</body></html>";
                $subject = sprintf('LotGD %s on %s', $label, $hostname);
                $body = $html_text;
                foreach ($sendto as $email) {
                    $output->debug("Notifying $email of this error.", true);
                    $admin = $settings->getSetting('gameadminemail', 'postmaster@localhost');
                    $from = [$admin => $admin];
                    \Lotgd\Mail::send([$email => $email], $body, $subject, $from, false, 'text/html');
                }
                $data['errors'][$msg] = strtotime('now');
            } else {
                $output->debug('Not notifying users for this error, it\'s only been ' . round((strtotime('now') - $data['errors'][$msg]) / 60, 2) . ' minutes.', true);
            }
        }
        if (
            $settings->getSetting('usedatacache', 0)
            && ! DataCache::getInstance()->updatedatacache('error_notify', $data)
        ) {
            error_log('Unable to write datacache for error_notify');
        }
        $output->debug($data, true);
    }

    /**
     * Handle uncaught exceptions.
     *
     * @param \Throwable $exception Exception instance
     * @return void
     */
    public static function handleException(\Throwable $exception): void
    {
        $trace = Backtrace::show($exception->getTrace());
        self::renderError($exception->getMessage(), $exception->getFile(), $exception->getLine(), $trace);

        $settings = Settings::hasInstance() ? Settings::getInstance() : null;
        $notify = $settings instanceof Settings
            ? (bool) $settings->getSetting('notify_on_error', 0)
            : false;

        if ($notify) {
            self::errorNotify(E_ERROR, $exception->getMessage(), $exception->getFile(), $exception->getLine(), $trace);
        }
    }

    /**
     * Catch fatal errors on shutdown.
     *
     * @return void
     */
    public static function fatalShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $trace = Backtrace::show();
            self::renderError($error['message'], $error['file'], $error['line'], $trace);
        }
    }

    /**
     * Register the handlers with PHP.
     *
     * @return void
     */
    public static function register(): void
    {
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'fatalShutdown']);
    }
}
