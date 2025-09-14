<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Settings;

/**
 * Simple spell checking helper used to highlight unknown words.
 */
class Spell
{
    /** @var array Cache for dictionary entries */
    private static array $dictionary = [];

    /**
     * Highlight words not found in the dictionary.
     *
     * @param string      $input   Text to process
     * @param string|false $words   Path to dictionary file
     * @param string      $prefix  Markup before a misspelled word
     * @param string      $postfix Markup after a misspelled word
     *
     * @return string Processed string
     */
    public static function check(string $input, string|false $words = false, string $prefix = "<span style='border: 1px dotted #FF0000;'>", string $postfix = "</span>"): string
    {
        $dict =& self::$dictionary;
        if ($words === false) {
            $words = Settings::getInstance()->getSetting('dictionary', '/usr/share/dict/words');
        }
        if (file_exists($words)) {
            if (!is_array($dict) || count($dict) == 0) {
                // Retrieve dictionary file once
                $lines = file($words);
                $lines = join('', $lines);
                $lines = explode("\n", $lines);
                $dict = array_flip($lines);
                $dict['a'] = 1;
                $dict['I'] = 1;
            }
            $contractions = [
                "n't" => "n't",
                "'s"  => "'s",
                "'ll" => "'ll",
                "'re" => "'re",
                "'ve" => "'ve",
                "'m"  => "'m",
                "'d"  => "'d",
            ];
            $parts = preg_split('/([<>])/', $input, -1, PREG_SPLIT_DELIM_CAPTURE);
            $intag = false;
            $output = '';
            foreach ($parts as $val) {
                if ($val == '<') {
                    $intag = true;
                } elseif ($val == '>') {
                    $intag = false;
                } elseif (!$intag) {
                    $line = preg_split('/([\t\n\r[:space:]-])/', $val, -1, PREG_SPLIT_DELIM_CAPTURE);
                    $val = '';
                    foreach ($line as $v) {
                        $lookups = [];
                        $i = 0;
                        $v1 = trim($v);
                        if ($v1 != '') {
                            $lookups[$v1] = $i++;
                            $lookups[strtolower($v1)] = $i++;
                        }
                        $v2 = preg_replace("/[.?!\"']+$/", '', $v);
                        foreach ($contractions as $cont => $throwaway) {
                            if (substr($v2, strlen($v2) - strlen($cont)) == $cont) {
                                $v1 = substr($v2, 0, strlen($v2) - strlen($cont));
                                if ($v1 != '') {
                                    $lookups[$v1] = $i++;
                                    $lookups[strtolower($v1)] = $i++;
                                }
                            }
                        }
                        $v1 = preg_replace('/[^a-zA-Z]/', '', trim($v));
                        if ($v1 != '') {
                            $lookups[$v1] = $i++;
                            $lookups[strtolower($v1)] = $i++;
                        }
                        if (count($lookups) > 0) {
                            $found = false;
                            foreach ($lookups as $k1 => $v1) {
                                if (isset($dict[$k1])) {
                                    $found = true;
                                    break;
                                }
                            }
                        } else {
                            $found = true;
                        }
                        if (!$found && preg_match('/[[:digit:]]/', $v)) {
                            $found = true;
                        }
                        if (!$found) {
                            $val .= $prefix . $v . $postfix;
                        } else {
                            $val .= $v;
                        }
                    }
                }
                $output .= $val;
            }
            return $output;
        }
        return $input;
    }
}
