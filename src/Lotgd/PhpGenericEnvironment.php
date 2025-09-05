<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Http;

/**
 * Provide functions for setting up PHP globals when running under various web servers.
 */
class PhpGenericEnvironment
{
    /**
     * Normalise REQUEST_URI and SCRIPT_NAME when running under unusual setups.
     */
    public static function sanitizeUri(): void
    {
        global $PATH_INFO, $SCRIPT_NAME, $REQUEST_URI;
        if (isset($PATH_INFO) && $PATH_INFO != '') {
            $SCRIPT_NAME = $PATH_INFO;
            $REQUEST_URI = '';
        }
        if ($REQUEST_URI == '') {
            // necessary for some IIS installations
            $get = Http::allGet();
            if (count($get) > 0) {
                $REQUEST_URI = $SCRIPT_NAME . '?';
                $i = 0;
                foreach ($get as $key => $val) {
                    if ($i > 0) {
                        $REQUEST_URI .= '&';
                    }
                    $REQUEST_URI .= "$key=" . URLEncode($val);
                    $i++;
                }
            } else {
                $REQUEST_URI = $SCRIPT_NAME;
            }
            $_SERVER['REQUEST_URI'] = $REQUEST_URI;
        }
        $SCRIPT_NAME = basename($SCRIPT_NAME);
        if (strpos($REQUEST_URI, '?')) {
            $REQUEST_URI = $SCRIPT_NAME . substr($REQUEST_URI, strpos($REQUEST_URI, '?'));
        } else {
            $REQUEST_URI = $SCRIPT_NAME;
        }
        $_SERVER['REQUEST_URI'] = $REQUEST_URI;
    }

    /**
     * Register global variables and sanitise the URI.
     */
    public static function setup(): void
    {
        RegisterGlobal::register($_SERVER);
        self::sanitizeUri();
    }
}
