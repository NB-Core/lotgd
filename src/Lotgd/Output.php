<?php

declare(strict_types=1);

/**
 * Collects formatted page output which can later be rendered by the template.
 * Refactored from the legacy output_collector class in lib/output.php.
 */

namespace Lotgd;

use Lotgd\DumpItem;
use Lotgd\HolidayText;
use Lotgd\Translator;
use Lotgd\Sanitize;
use Lotgd\Modules\ModuleManager;

class Output
{
    private static ?self $instance = null;

    private $output;             // text collected for display
    private $block_new_output;   // whether new output should be ignored
    private $colors;             // color code => CSS class
    private $colormap;           // color code keys
    private $colormap_esc;       // escaped color code keys
    private $nestedtags;         // open html tags during output
    private $nestedtags_eval;    // eval callbacks for special tags

    /**
     * Initialize with default color codes and tag tracking.
     */
    public function __construct()
    {
        $this->output           = '';
        $this->nestedtags       = [];
        $this->nestedtags_eval  = [];
        $this->block_new_output = false;
        $this->nestedtags['font'] = false;
        $this->nestedtags['div']  = false;
        $this->nestedtags['span'] = false;
        $this->nestedtags['i']    = false;
        $this->nestedtags['b']    = false;
        $this->nestedtags['<']    = false;
        $this->nestedtags['>']    = false;
        $this->nestedtags['B']    = false;
        $this->colors = [
            '1' => 'colDkBlue',
            '2' => 'colDkGreen',
            '3' => 'colDkCyan',
            '4' => 'colDkRed',
            '5' => 'colDkMagenta',
            '6' => 'colDkYellow',
            '7' => 'colDkWhite',
            '!' => 'colLtBlue',
            '@' => 'colLtGreen',
            '#' => 'colLtCyan',
            '$' => 'colLtRed',
            '%' => 'colLtMagenta',
            '^' => 'colLtYellow',
            '&' => 'colLtWhite',
            'q' => 'colDkOrange',
            'Q' => 'colLtOrange',
            ')' => 'colLtBlack',
            'R' => 'colRose',
            'V' => 'colBlueViolet',
            'v' => 'coliceviolet',
            'g' => 'colXLtGreen',
            'G' => 'colXLtGreen',
            'T' => 'colDkBrown',
            't' => 'colLtBrown',
            '~' => 'colBlack',
            'e' => 'colDkRust',
            'E' => 'colLtRust',
            'j' => 'colMdGrey',
            'J' => 'colMdBlue',
            'l' => 'colDkLinkBlue',
            'L' => 'colLtLinkBlue',
            'x' => 'colburlywood',
            'X' => 'colbeige',
            'y' => 'colkhaki',
            'Y' => 'coldarkkhaki',
            'k' => 'colaquamarine',
            'K' => 'coldarkseagreen',
            'p' => 'collightsalmon',
            'P' => 'colsalmon',
            'm' => 'colwheat',
            'M' => 'coltan',
        ];
        // build maps used by sanitize functions
        $this->setColorMap();
    }

    /**
     * Retrieve the global Output instance.
     */
    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Append raw text to the output buffer.
     */
    public function rawOutput(string $indata): void
    {
        if ($this->block_new_output) {
            return;
        }
        $this->output .= $indata . "\n";
    }

    /**
     * Handle color encoding and append to the output buffer.
     */
    public function outputNotl()
    {
        if ($this->block_new_output) {
            return;
        }
        $args   = func_get_args();
        $length = count($args);
        $last   = $args[$length - 1];
        if ($last !== true) {
            $priv = false;
        } else {
            unset($args[$length - 1]);
            $priv = true;
        }
        $out =& $args[0];
        if (count($args) > 1) {
            $out = str_replace("`%", "`%%", $out);
            $out = call_user_func_array('sprintf', $args);
        }
        if ($priv == false) {
            $out = HolidayText::holidayize($out, 'output');
        }
        $out = $this->appoencode($out, $priv);
        $this->output .= Translator::tlbuttonPop() . $out . "\n";
    }

    /**
     * Translate a string and add it to the buffer with formatting.
     */
    public function output()
    {
        if ($this->block_new_output) {
            return;
        }
        $args = func_get_args();
        if (is_array($args[0])) {
            $args = $args[0];
        }
        if (is_bool($args[0]) && array_shift($args)) {
            $schema  = array_shift($args);
            $args[0] = Translator::translate($args[0], $schema);
        } else {
            $args[0] = Translator::translate($args[0]);
        }
        call_user_func_array([$this, 'outputNotl'], $args);
    }

    /**
     * Get the formatted output closing any left open tags.
     */
    public function getOutput()
    {
        $output = $this->output;
        foreach (array_keys($this->nestedtags) as $key => $val) {
            if ($key == 'font') {
                $key = 'span';
            }
            if ($val === true) {
                $output .= "</" . $key . ">";
            }
        }
        return $output;
    }

    /**
     * Return raw buffered output without adding closing tags.
     */
    public function getRawOutput()
    {
        return $this->output;
    }

    /**
     * Enable or disable output collection.
     */
    public function setBlockNewOutput($block)
    {
        $this->block_new_output = ($block ? true : false);
    }

    /**
     * Determine whether new output is blocked.
     */
    public function getBlockNewOutput()
    {
        return $this->block_new_output;
    }

    /**
     * Add debug information to the output stream.
     */
    public function debug($text, $force = false)
    {
        global $session;

        $temp = $this->getBlockNewOutput();
        $this->setBlockNewOutput(false);

        if ($force || (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT))) {
            if (is_array($text)) {
                $text = $this->appoencode(DumpItem::dump($text), true);
            }

            $origin = ModuleManager::getMostRecentModule() ?? '';

            if ('' === $origin) {
                $trace  = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
                $origin = basename($trace[2]['file'] ?? '');
            }

            $text = "{$origin}: {$text}";

            // Toggle visibility of debug output to make the page cleaner if you want to
            $this->rawOutput("<button onclick=\"this.nextElementSibling.classList.toggle('hidden');\">Show Debug Output</button><div class='debug'>$text</div>");
        }

        $this->setBlockNewOutput($temp);
    }

    /**
     * Replace lotgd colour codes within a string by HTML tags.
     */
    public function appoencode($data, $priv = false)
    {
        $settings = Settings::hasInstance() ? Settings::getInstance() : null;
        $charset = $settings instanceof Settings
            ? $settings->getSetting('charset', 'UTF-8')
            : 'UTF-8';
        $start = 0;
        $out   = '';
        if (($pos = strpos($data, '`')) !== false) {
            do {
                ++$pos;
                if (!isset($data[$pos])) {
                    continue;
                }
                if ($priv === false) {
                    $out .= HTMLEntities(substr($data, $start, $pos - $start - 1), ENT_COMPAT, $charset);
                } else {
                    $out .= substr($data, $start, $pos - $start - 1);
                }
                $start = $pos + 1;
                if (isset($data[$pos]) && isset($this->colors[$data[$pos]])) {
                    if ($this->nestedtags['font']) {
                        $out .= '</span>';
                    } else {
                        $this->nestedtags['font'] = true;
                    }
                    $out .= "<span class='" . $this->colors[$data[$pos]] . "'>";
                } else {
                    if (isset($this->nestedtags_eval[$data[$pos]])) {
                        $func = $this->nestedtags_eval[$data[$pos]];
                        eval($func);
                        continue;
                    }
                    switch ($data[$pos]) {
                        case 'n':
                            $out .= "<br>\n";
                            break;
                        case '0':
                            if ($this->nestedtags['font']) {
                                $out .= '</span>';
                            }
                            $this->nestedtags['font'] = false;
                            break;
                        case 'b':
                            if ($this->nestedtags['b']) {
                                $out .= '</b>';
                                $this->nestedtags['b'] = false;
                            } else {
                                $this->nestedtags['b'] = true;
                                $out .= '<b>';
                            }
                            break;
                        case 'i':
                            if ($this->nestedtags['i']) {
                                $out .= '</i>';
                                $this->nestedtags['i'] = false;
                            } else {
                                $this->nestedtags['i'] = true;
                                $out .= '<i>';
                            }
                            break;
                        case 'c':
                            if ($this->nestedtags['div']) {
                                $out .= '</div>';
                                $this->nestedtags['div'] = false;
                            } else {
                                $this->nestedtags['div'] = true;
                                $out .= "<div align='center'>";
                            }
                            break;
                        case 'B':
                            if ($this->nestedtags['B']) {
                                $out .= '</em>';
                                $this->nestedtags['B'] = false;
                            } else {
                                $this->nestedtags['B'] = true;
                                $out .= '<em>';
                            }
                            break;
                        case '>':
                            if ($this->nestedtags['>']) {
                                $this->nestedtags['>'] = false;
                                $out .= '</div>';
                            } else {
                                $this->nestedtags['>'] = true;
                                $out .= "<div style='float: right; clear: right;'>";
                            }
                            break;
                        case '<':
                            if ($this->nestedtags['<']) {
                                $this->nestedtags['<'] = false;
                                $out .= '</div>';
                            } else {
                                $this->nestedtags['<'] = true;
                                $out .= "<div style='float: left; clear: left;'>";
                            }
                            break;
                        case 'H':
                            if ($this->nestedtags['span']) {
                                $out .= '</span>';
                                $this->nestedtags['span'] = false;
                            } else {
                                $this->nestedtags['span'] = true;
                                $out .= "<span class='navhi'>";
                            }
                            break;
                        case 'w':
                            global $session;
                            if (!isset($session['user']['weapon'])) {
                                $session['user']['weapon'] = '';
                            }
                            $out .= Sanitize::sanitize($session['user']['weapon']);
                            break;
                        case '`':
                            $out .= '`';
                            ++$pos;
                            break;
                        default:
                            $out .= '`' . $data[$pos];
                    }
                }
            } while (($pos = strpos($data, '`', $pos)) !== false);
        }
        if ($priv === false) {
            $out .= HTMLEntities(substr($data, $start), ENT_COMPAT, $charset);
        } else {
            $out .= substr($data, $start);
        }
        return $out;
    }

    public function setColorMap()
    {
        $escape = [')', '$', '(', '[', ']', '{', '}'];
        $cols   = $this->colors;
        foreach ($escape as $letter) {
            if (isset($cols[$letter])) {
                $cols['\\' . $letter] = $cols[$letter];
            }
            unset($cols[$letter]);
        }
        $this->colormap_esc = array_keys($cols); // codes used for sanitizing
        $this->colormap     = array_keys($this->colors);
    }

    public function getColors()
    {
        return $this->colors;
    }

    public function setColors($colors)
    {
        $this->colors = $colors;
        $this->setColorMap();
    }

    public function getNestedTags()
    {
        return $this->nestedtags;
    }

    public function setNestedTags($tags)
    {
        $this->nestedtags = $tags;
    }

    public function setNestedTagEval($nested_eval)
    {
        $this->nestedtags_eval = $nested_eval;
    }

    public function getNestedTagEval()
    {
        return $this->nestedtags_eval;
    }

    public function hasOpenFont(): bool
    {
        return !empty($this->nestedtags['font']);
    }

    public function closeOpenFont(): void
    {
        if ($this->hasOpenFont()) {
            $this->appoencode('`0');
        }
    }

    public function getColormap()
    {
        return implode('', $this->colormap);
    }

    public function getColormapEscaped()
    {
        return implode('', $this->colormap_esc);
    }

    public function getColormapEscapedArray()
    {
        return $this->colormap_esc;
    }
}
