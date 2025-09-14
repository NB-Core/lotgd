<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\MySQL\Database;
use Lotgd\Sanitize;
use Lotgd\Modules\HookHandler;
use Lotgd\Settings;
use Lotgd\DataCache;
use Lotgd\Output;

use const SU_EDIT_COMMENTS;

class Censor
{
    /**
     * Filter a text string for banned words.
     *
     * @param string $input    Input string
     * @param bool   $debug    Output debug information
     * @param bool   $skiphook Skip module hook
     *
     * @return string Filtered string
     */
    public static function soap(string $input, bool $debug = false, bool $skiphook = false): string
    {
        global $session;
        $output = Output::getInstance();
        $final_output = $input;
        $sanitized = Sanitize::fullSanitize($input);
        $mix_mask = str_pad('', strlen($sanitized), 'X');
        $settings = Settings::getInstance();
        if ($settings->getSetting('soap', 1)) {
            $search = self::nastyWordList();
            $exceptions = array_flip(self::goodWordList());
            $changed_content = false;
            foreach ($search as $word) {
                $matches = [];
                do {
                    if ($word > '') {
                        $times = preg_match_all($word, $sanitized, $matches);
                    } else {
                        $times = 0;
                    }
                    for ($x = 0; $x < $times; $x++) {
                        if (strlen($matches[0][$x]) < strlen($matches[1][$x])) {
                            $shortword = $matches[0][$x];
                            $longword = $matches[1][$x];
                        } else {
                            $shortword = $matches[1][$x];
                            $longword = $matches[0][$x];
                        }
                        if (isset($exceptions[strtolower($longword)])) {
                            $x--;
                            $times--;
                            if ($debug) {
                                $output->output("This word is ok because it was caught by an exception: `b`^%s`7`b`n", $longword);
                            }
                        } else {
                            if ($debug) {
                                $output->output("`7This word is not ok: \"`%%s`7\"; it blocks on the pattern `i%s`i at \"`\$%s`7\".`n", Sanitize::sanitizeMb($longword), $word, $shortword);
                            }
                            $len = strlen($shortword);
                            $pad = str_pad('', $len, '_');
                            $p = strpos($sanitized, $shortword);
                            $sanitized = substr($sanitized, 0, $p) . $pad . substr($sanitized, $p + $len);
                            $mix_mask = substr($mix_mask, 0, $p) . $pad . substr($mix_mask, $p + $len);
                            $changed_content = true;
                        }
                    }
                } while ($times > 0);
            }
            $y = 0;
            $pad = '#@%$!';
            for ($x = 0; $x < strlen($mix_mask); $x++) {
                while (substr($final_output, $y, 1) == '`') {
                    $y += 2;
                }
                if (substr($mix_mask, $x, 1) == '_') {
                    $final_output = substr($final_output, 0, $y) . substr($pad, $x % strlen($pad), 1) . substr($final_output, $y + 1);
                }
                $y++;
            }
            if (($session['user']['superuser'] & SU_EDIT_COMMENTS) && $changed_content) {
                $output->output("`0The filter would have tripped on \"`#%s`0\" but since you're a moderator, I'm going to be lenient on you.  The text would have read, \"`#%s`0\"`n`n", $input, $final_output);
                return $input;
            }
            if ($changed_content && !$skiphook) {
                HookHandler::hook('censor', ['input' => $input]);
            }
            return $final_output;
        }
        return $final_output;
    }

    /**
     * Retrieve exception words that bypass the filter.
     *
     * @return array<string> List of allowed words
     */
    public static function goodWordList(): array
    {
        $sql = 'SELECT * FROM ' . Database::prefix('nastywords') . " WHERE type='good'";
        $result = Database::queryCached($sql, 'goodwordlist');
        $row = Database::fetchAssoc($result);
        if (!isset($row['words'])) {
            return [];
        }
        return explode(' ', $row['words']);
    }

    /**
     * List of banned words used by the filter.
     *
     * @return array<string> Compiled regexes
     */
    public static function nastyWordList(): array
    {
        $sql = 'SELECT * FROM ' . Database::prefix('nastywords') . " WHERE type='nasty'";
        $result = Database::query($sql);
        $row = Database::fetchAssoc($result);
        $search = ' ' . $row['words'] . ' ';
        $search = preg_replace('/(?<=.)(?<!\\\\)\'(?=.)/', '\\\'', $search);

        $search = str_replace('b', '[b]', $search);
        $search = str_replace('d', '[d]', $search);
        $search = str_replace('e', '[e3]', $search);
        $search = str_replace('n', '[n]', $search);
        $search = str_replace('o', '[o0]', $search);
        $search = str_replace('p', '[p]', $search);
        $search = str_replace('r', '[r]', $search);

        $search = str_replace('t', '[t7+]', $search);
        $search = str_replace('u', '[u]', $search);
        $search = str_replace('x', '[xפ]', $search);
        $search = str_replace('y', '[yݥ]', $search);
        $search = str_replace('l', '[l1!]', $search);
        $search = str_replace('i', '[li1!]', $search);
        $search = str_replace('k', 'c', $search);
        $search = str_replace('c', '[c\\(k穢]', $search);
        $start = '\'\\b';
        $end = '\\b\'iU';
        $ws = "[^[:space:]\\t]*"; //whitespace (\w is not hungry enough)
        //space not preceeded by a star
        $search = preg_replace("'(?<!\\*) '", ")+$end ", $search);
        //space not anteceeded by a star
        $search = preg_replace("' (?!\\*)'", " $start(", $search);
        //space preceeded by a star
        $search = str_replace("* ", ")+$ws$end ", $search);
        //space anteceeded by a star
        $search = str_replace(" *", " $start$ws(", $search);
        $search = "$start(" . trim($search) . ")+$end";
        $search = str_replace("$start()+$end", "", $search);
        $search = explode(" ", $search);
        DataCache::getInstance()->updatedatacache('nastywordlist', $search);

        return $search;
    }
}
