<?php
namespace Lotgd;

class Http
{
    public static function get(string $var)
    {
        $res = $_GET[$var] ?? false;
        if ($res === '') {
            $res = $GLOBALS['HTTP_GET_VARS'][$var] ?? false;
        }
        return $res;
    }

    public static function allGet(): array
    {
        return $_GET;
    }

    public static function set(string $var, $val, bool $force=false): void
    {
        if (isset($_GET[$var]) || $force) $_GET[$var] = $val;
        if (isset($GLOBALS['HTTP_GET_VARS'][$var])) $GLOBALS['HTTP_GET_VARS'][$var] = $val;
    }

    public static function post(string $var)
    {
        $res = $_POST[$var] ?? false;
        if ($res === '') {
            $res = $GLOBALS['HTTP_POST_VARS'][$var] ?? false;
        }
        return $res;
    }

    public static function postIsset(string $var): bool
    {
        $res = isset($_POST[$var]) ? 1 : 0;
        if ($res === '') {
            $res = isset($GLOBALS['HTTP_POST_VARS'][$var]) ? 1 : 0;
        }
        return (bool)$res;
    }

    public static function postSet(string $var, $val, $sub=false): void
    {
        if ($sub === false) {
            if (isset($_POST[$var])) $_POST[$var] = $val;
            if (isset($GLOBALS['HTTP_POST_VARS'][$var])) $GLOBALS['HTTP_POST_VARS'][$var] = $val;
        } else {
            if (isset($_POST[$var]) && isset($_POST[$var][$sub]))
                $_POST[$var][$sub]=$val;
            if (isset($GLOBALS['HTTP_POST_VARS'][$var]) && isset($GLOBALS['HTTP_POST_VARS'][$var][$sub]))
                $GLOBALS['HTTP_POST_VARS'][$var][$sub]=$val;
        }
    }

    public static function allPost(): array
    {
        return $_POST;
    }

    public static function postParse($verify=false, $subval=false): array
    {
        $var = $subval ? $_POST[$subval] : $_POST;
        $sql = '';
        $keys = '';
        $vals = '';
        $i = 0;
        foreach ($var as $key=>$val) {
            if ($verify === false || isset($verify[$key])) {
                if (is_array($val)) $val = addslashes(serialize($val));
                $sql .= (($i > 0) ? ',' : '') . "$key='$val'";
                $keys .= (($i > 0) ? ',' : '') . "$key";
                $vals .= (($i > 0) ? ',' : '') . "'$val'";
                $i++;
            }
        }
        return [$sql, $keys, $vals];
    }
}
