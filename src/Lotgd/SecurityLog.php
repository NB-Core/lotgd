<?php

declare(strict_types=1);

/**
 * Single entry point for security-relevant outcomes.
 *
 * Before this class existed, the events AGENTS.md requires to be logged were
 * scattered across three unrelated sinks: some went to the per-character audit
 * trail ({@see DebugLog}), some only to PHP's error log, and some nowhere an
 * administrator could reach them. This class writes one event to both channels
 * that matter:
 *
 *  - the game log (category {@see GameLog::CATEGORY_SECURITY}), where an
 *    administrator reviews them through gamelog.php, and
 *  - PHP's error log, where they can be correlated with the container/web
 *    server logs.
 *
 * Every event carries a short correlation id so a line in the container log and
 * a row in the game log can be tied together, and so an operator-facing error
 * payload can reference an event without disclosing its contents.
 */

namespace Lotgd;

class SecurityLog
{
    /**
     * Record a security-relevant outcome.
     *
     * @param string               $message  Human readable description of what was refused or detected.
     * @param array<string,scalar|null> $context Structured detail rendered as `key=value` pairs.
     * @param int|null             $acctId   Account the event is attributed to; null uses the session user.
     * @param string               $severity One of the {@see GameLog} severity constants.
     * @param bool                 $persist  When false the event is only written to PHP's error log.
     *                                       Used for high-frequency paths that must not let an
     *                                       unauthenticated caller drive database writes.
     *
     * @return string The correlation id assigned to this event.
     */
    public static function event(
        string $message,
        array $context = [],
        ?int $acctId = null,
        string $severity = GameLog::SEVERITY_WARNING,
        bool $persist = true
    ): string {
        $diagnosticId = self::correlationId();
        $context = ['diag' => $diagnosticId] + $context;

        $line = sprintf('[security] %s [%s]', self::sanitize($message), self::renderContext($context));
        error_log($line);

        if ($persist) {
            GameLog::log(
                sprintf('%s [%s]', self::sanitize($message), self::renderContext($context)),
                GameLog::CATEGORY_SECURITY,
                false,
                $acctId,
                $severity
            );
        }

        return $diagnosticId;
    }

    /**
     * Generate a short correlation id shared by the log line and the game log row.
     */
    public static function correlationId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return uniqid('diag_', true);
        }
    }

    /**
     * Render structured context as a stable, single-line `key=value` list.
     *
     * @param array<string,scalar|null> $context
     */
    private static function renderContext(array $context): string
    {
        $parts = [];
        foreach ($context as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            }
            $parts[] = self::sanitize((string) $key) . '=' . self::sanitize((string) $value);
        }

        return implode(' ', $parts);
    }

    /**
     * Strip control characters so attacker-supplied values cannot forge log lines.
     *
     * Mirrors the sanitising already performed on async dispatch tokens: a value
     * that reaches this class may come straight from a request parameter.
     */
    private static function sanitize(string $value): string
    {
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        if ($sanitized === null) {
            // preg_replace returns null on malformed UTF-8; fall back to a byte-safe filter.
            $sanitized = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';
        }

        return trim($sanitized);
    }
}
