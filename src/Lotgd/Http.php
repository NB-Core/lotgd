<?php

declare(strict_types=1);

namespace Lotgd;

class Http
{
    /**
     * Retrieve a value from the GET superglobal.
     */
    public static function get(string $var): string|false
    {
        $res = $_GET[$var] ?? false;
        if ($res === '') {
            $res = $GLOBALS['HTTP_GET_VARS'][$var] ?? false;
        }
        return $res;
    }

    /**
     * Return the entire $_GET array.
     */
    public static function allGet(): array
    {
        return $_GET;
    }

    /**
     * Set a GET variable.
     */
    public static function set(string $var, mixed $val, bool $force = false): void
    {
        if (isset($_GET[$var]) || $force) {
            $_GET[$var] = $val;
        }
        if (isset($GLOBALS['HTTP_GET_VARS'][$var])) {
            $GLOBALS['HTTP_GET_VARS'][$var] = $val;
        }
    }

    /**
     * Retrieve a value from the POST superglobal.
     */
    public static function post(string $var): string|array|false
    {
        $res = $_POST[$var] ?? false;
        if ($res === '') {
            $res = $GLOBALS['HTTP_POST_VARS'][$var] ?? false;
        }
        return $res;
    }

    /** Check if a POST variable exists. */
    public static function postIsset(string $var): bool
    {
        $res = isset($_POST[$var]) ? 1 : 0;
        return (bool)$res;
    }

    /**
     * Set a value in the POST array.
     */
    public static function postSet(string $var, mixed $val, string|false $sub = false): void
    {
        if ($sub === false) {
            if (isset($_POST[$var])) {
                $_POST[$var] = $val;
            }
            if (isset($GLOBALS['HTTP_POST_VARS'][$var])) {
                $GLOBALS['HTTP_POST_VARS'][$var] = $val;
            }
        } else {
            if (isset($_POST[$var]) && isset($_POST[$var][$sub])) {
                $_POST[$var][$sub] = $val;
            }
            if (isset($GLOBALS['HTTP_POST_VARS'][$var]) && isset($GLOBALS['HTTP_POST_VARS'][$var][$sub])) {
                $GLOBALS['HTTP_POST_VARS'][$var][$sub] = $val;
            }
        }
    }

    /** Return the entire $_POST array. */
    public static function allPost(): array
    {
        return $_POST;
    }

    /**
     * Prepare column, placeholder, and parameter lists from POST data.
     *
     * @return array{0: array<string>, 1: array<string>, 2: array<mixed>}
     */
    public static function postParse(array|false $verify = false, string|false $subval = false): array
    {
        $var = $subval ? ($_POST[$subval] ?? []) : $_POST;

        if (!is_array($var)) {
            return [[], [], []];
        }

        $columns = [];
        $placeholders = [];
        $parameters = [];

        foreach ($var as $key => $val) {
            if ($verify !== false && !isset($verify[$key])) {
                continue;
            }

            if (is_array($val)) {
                $val = serialize($val);
            }

            $columns[] = (string) $key;
            $placeholders[] = '?';
            $parameters[] = $val;
        }

        return [$columns, $placeholders, $parameters];
    }
}
