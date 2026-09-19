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
     * Make a value safe to put in a log line: valid UTF-8, and no control
     * characters an attacker could use to forge a second line.
     *
     * A value that reaches this class may come straight from a request
     * parameter, so neither property can be assumed.
     *
     * The encoding half is not cosmetic. This class writes to two channels and
     * they do not agree about invalid bytes: error_log() takes anything, while
     * the game log is an INSERT over a utf8mb4 connection, which rejects a
     * malformed string outright. So an event carrying one byte of garbage did
     * not merely arrive looking odd -- the write raised, from inside the logger,
     * on the path of a refusal that was being recorded precisely because
     * something had already gone wrong. The event an operator most needs is the
     * one that was thrown away.
     *
     * The previous version of this method *detected* that case and then did
     * nothing about it: preg_replace() with /u returns null on malformed UTF-8,
     * and the fallback stripped control bytes without touching the malformed
     * ones, so the output was as invalid as the input. Measured on
     * `login=\xC3\x28admin\xFF`: byte-identical, still invalid.
     */
    private static function sanitize(string $value): string
    {
        $value = self::toValidUtf8($value);

        $sanitized = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value);
        if ($sanitized === null) {
            // Unreachable by way of encoding now that the subject is valid, and
            // kept for the other reasons a PCRE call can fail (backtrack and
            // recursion limits). Byte-wise is safe here where it was not
            // before: these are ASCII control bytes, which cannot be part of a
            // multi-byte sequence, so removing them leaves valid UTF-8 valid.
            $sanitized = preg_replace('/[\x00-\x1F\x7F]/', ' ', $value) ?? '';
        }

        return trim($sanitized);
    }

    /**
     * Make a value valid UTF-8, leaving input that already is alone.
     *
     * Where mbstring is present the malformed sequences are marked with U+FFFD,
     * which is the better outcome and is what this asks for; where it is not,
     * they are dropped. The guarantee is the validity -- see the last paragraph
     * for why the marking cannot be one.
     *
     * Marking rather than dropping, because the whole point of the line is to
     * tell an operator what was seen: a replacement character says "there was
     * something unreadable here", where a silent deletion makes a mangled value
     * look like a value someone actually sent.
     *
     * mb_substitute_character() is saved and restored because it is global
     * state and this class is called from everywhere, and it is set explicitly
     * rather than left at its default -- which is `?`, a character a request
     * can legitimately contain, and an ini setting an installation can change.
     *
     * One thing this does *not* promise, and an earlier version of this
     * docblock wrongly did: identical output everywhere. Where the mbstring
     * extension is absent, symfony/polyfill-mbstring supplies these functions,
     * and its mb_substitute_character() returns false for a codepoint instead
     * of setting one -- so the malformed bytes are dropped rather than marked.
     * Measured against the polyfill directly: `login=\xC3\x28probe\xFF` comes
     * back as `login=(probe`. What holds either way is the property this method
     * exists for: the result is valid UTF-8, so the game log can take the row.
     * Marking is the better outcome and the extension is what makes it
     * available. Reported by Copilot.
     */
    private static function toValidUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        } finally {
            mb_substitute_character($previous);
        }
    }
}
