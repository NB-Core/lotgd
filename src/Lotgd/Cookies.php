<?php
declare(strict_types=1);

namespace Lotgd;

class Cookies
{
    /**
     * Set a cookie value with common defaults.
     */
    public static function set(string $name, string $value, int $expires, bool $secure = false): void
    {
        setcookie($name, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$name] = $value;
    }

    /**
     * Delete a cookie by expiring it.
     */
    public static function delete(string $name): void
    {
        setcookie($name, '', [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => ServerFunctions::isSecureConnection(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$name]);
    }

    /**
     * Get a cookie value if set.
     */
    public static function get(string $name): ?string
    {
        return isset($_COOKIE[$name]) ? (string) $_COOKIE[$name] : null;
    }

    /**
     * Set the unique id cookie with security flags.
     */
    public static function setLgi(string $id): void
    {
        if (strlen($id) < 32) {
            return;
        }
        $expires = strtotime('+365 days');
        self::set('lgi', $id, $expires, ServerFunctions::isSecureConnection());
    }

    /**
     * Retrieve the unique id cookie if valid.
     */
    public static function getLgi(): ?string
    {
        $id = self::get('lgi');
        if ($id === null || strlen($id) < 32) {
            return null;
        }
        return $id;
    }

    /**
     * Set the template cookie value after sanitizing.
     *
     * @param string $template Template identifier
     *
     * @return void
     */
    public static function setTemplate(string $template): void
    {
        $template = preg_replace(self::SANITIZATION_REGEX, '', $template);

        if ($template === '') {
            self::delete('template');

            return;
        }

        $expires = strtotime('+45 days');
        self::set('template', $template, $expires, ServerFunctions::isSecureConnection());
    }

    /**
     * Get the sanitized template cookie value.
     */
    public static function getTemplate(): string
    {
        $template = self::get('template') ?? '';

        return preg_replace('/[^a-zA-Z0-9:_-]/', '', $template);
    }
}
