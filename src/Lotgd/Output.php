<?php
namespace Lotgd;

use Lotgd\DumpItem;
use Lotgd\HolidayText;

/**
 * Collects formatted page output which can later be rendered by the template.
 * Refactored from the legacy output_collector class in lib/output.php.
 */
class Output
{
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
        $this->set_color_map();
    }

    /**
     * Append raw text to the output buffer.
     */
    public function rawoutput(string $indata): void
    {
        if ($this->block_new_output) {
            return;
        }
        $this->output .= $indata . "\n";
    }

    /**
     * Handle color encoding and append to the output buffer.
     */
    public function output_notl()
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
        $this->output .= tlbutton_pop() . $out . "\n";
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
            $args[0] = translate($args[0], $schema);
        } else {
            $args[0] = translate($args[0]);
        }
        call_user_func_array([$this, 'output_notl'], $args);
    }

    /**
     * Get the formatted output closing any left open tags.
     */
    public function get_output()
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
    public function get_rawoutput()
    {
        return $this->output;
    }

    /**
     * Enable or disable output collection.
     */
    public function set_block_new_output($block)
    {
        $this->block_new_output = ($block ? true : false);
    }

    /**
     * Determine whether new output is blocked.
     */
    public function get_block_new_output()
    {
        return $this->block_new_output;
    }

    /**
     * Add debug information to the output stream.
     */
    public function debug($text, $force = false)
    {
        global $session;
        $temp = $this->get_block_new_output();
        $this->set_block_new_output(false);
        if ($force || (isset($session['user']['superuser']) && ($session['user']['superuser'] & SU_DEBUG_OUTPUT))) {
            if (is_array($text)) {
                $text = appoencode(DumpItem::dump($text), true);
            }
            $this->rawoutput("<div class='debug'>$text</div>");
        }
        $this->set_block_new_output($temp);
    }

    /**
     * Replace lotgd colour codes within a string by HTML tags.
     */
    public function appoencode($data, $priv = false)
    {
        $start = 0;
        $out   = '';
        if (($pos = strpos($data, '`')) !== false) {
            do {
                ++$pos;
                if (!isset($data[$pos])) {
                    continue;
                }
                if ($priv === false) {
                    $out .= HTMLEntities(substr($data, $start, $pos - $start - 1), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'));
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
                            $out .= sanitize($session['user']['weapon']);
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
            $out .= HTMLEntities(substr($data, $start), ENT_COMPAT, getsetting('charset', 'ISO-8859-1'));
        } else {
            $out .= substr($data, $start);
        }
        return $out;
    }

    public function set_color_map()
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

    public function get_colors()
    {
        return $this->colors;
    }

    public function set_colors($colors)
    {
        $this->colors = $colors;
        $this->set_color_map();
    }

    public function get_nested_tags()
    {
        return $this->nestedtags;
    }

    public function set_nested_tags($tags)
    {
        $this->nestedtags = $tags;
    }

    public function set_nested_tag_eval($nested_eval)
    {
        $this->nestedtags_eval = $nested_eval;
    }

    public function get_nested_tag_eval()
    {
        return $this->nestedtags_eval;
    }

    public function get_colormap()
    {
        return implode('', $this->colormap);
    }

    public function get_colormap_escaped()
    {
        return implode('', $this->colormap_esc);
    }

    public function get_colormap_escaped_array()
    {
        return $this->colormap_esc;
    }
}
