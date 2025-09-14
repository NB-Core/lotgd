<?php

declare(strict_types=1);

namespace Lotgd\Async\Handler;

use Lotgd\MySQL\Database;
use Lotgd\Output;
use Lotgd\Settings;
use Lotgd\Translator;
use Jaxon\Response\Response;

use function Jaxon\jaxon;

/**
 * Handle asynchronous timeout checks.
 */
class Timeout
{
    private static ?self $instance = null;

    private int $startTimeoutShowSeconds = 300;

    private bool $neverTimeoutIfBrowserOpen = false;

    private int $checkMailTimeoutSeconds = 10;

    private int $clearScriptExecutionSeconds = -1;

    private function __construct()
    {
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function setStartTimeoutShowSeconds(int $seconds): void
    {
        $this->startTimeoutShowSeconds = $seconds;
    }

    public function getStartTimeoutShowSeconds(): int
    {
        return $this->startTimeoutShowSeconds;
    }

    public function setNeverTimeoutIfBrowserOpen(bool $never): void
    {
        $this->neverTimeoutIfBrowserOpen = $never;
    }

    public function isNeverTimeoutIfBrowserOpen(): bool
    {
        return $this->neverTimeoutIfBrowserOpen;
    }

    public function setCheckMailTimeoutSeconds(int $seconds): void
    {
        $this->checkMailTimeoutSeconds = $seconds;
    }

    public function getCheckMailTimeoutSeconds(): int
    {
        return $this->checkMailTimeoutSeconds;
    }

    public function setClearScriptExecutionSeconds(int $seconds): void
    {
        $this->clearScriptExecutionSeconds = $seconds;
    }

    public function getClearScriptExecutionSeconds(): int
    {
        return $this->clearScriptExecutionSeconds;
    }

    /**
     * Update last activity time and report remaining session timeout.
     */
    public function timeoutStatus(bool $args = false): Response
    {
        global $session;
        $output = Output::getInstance();
        $settings = Settings::getInstance();

        if ($args === false) {
            return jaxon()->newResponse();
        }

        if (!isset($session['user'])) {
            return jaxon()->newResponse();
        }

        $warning = '';

        if ($this->isNeverTimeoutIfBrowserOpen()) {
            $session['user']['laston'] = date('Y-m-d H:i:s'); // set to now
            // manual db update
            $sql = 'UPDATE ' . Database::prefix('accounts') . " set laston='" . $session['user']['laston']
                . "' WHERE acctid=" . $session['user']['acctid'];
            Database::query($sql);
        }

        $timeout = strtotime($session['user']['laston']) - strtotime(date('Y-m-d H:i:s', strtotime('-' . $settings->getSetting('LOGINTIMEOUT', 900) . ' seconds')));
        Translator::enableTranslation(false);

        if ($timeout <= 1) {
            // Preserve legacy behaviour by including the TIMEOUT keyword
            $warning = $output->appoencode('`$`b') . 'TIMEOUT: Your session has timed out!' . $output->appoencode('`b');
        } elseif ($timeout < $this->getStartTimeoutShowSeconds()) {
            if ($timeout > 60) {
                $min = floor($timeout / 60);
                $sec = $timeout % 60;
                $warning = '<br/>' . $output->appoencode('`t')
                    . Translator::sprintfTranslate('TIMEOUT in %d minute%s und %d second%s!', $min, $min > 1 ? Translator::translateInline('s', 'notranslate') : '', $sec, $sec != 1 ? Translator::translateInline('s', 'notranslate') : '');
            } else {
                $warning = '<br/>' . $output->appoencode('`t')
                    . Translator::sprintfTranslate('TIMEOUT in %d second%s!', $timeout, $timeout != 1 ? Translator::translateInline('s', 'notranslate') : '');
            }
        } else {
            $warning = '';
        }

        $objResponse = jaxon()->newResponse();
        $objResponse->assign('notify', 'innerHTML', $warning);

        Translator::enableTranslation(true);
        return $objResponse;
    }
}
