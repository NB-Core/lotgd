<?php

declare(strict_types=1);

namespace Lotgd;

use Lotgd\Settings;
use Lotgd\DumpItem;
use Lotgd\Modules\HookHandler;
use Lotgd\Output;
use Lotgd\Translator;
use Lotgd\Http;
use Lotgd\DataCache;

class Forms
{
    /**
     * Render a message preview input field with javascript helper.
     */
    public static function previewField(
        string $name,
        string|bool $startdiv = false,
        string $talkline = 'says',
        bool $showcharsleft = true,
        array|bool $info = false,
        bool $scriptOutput = true
    ): string {
        global $session;
        $output = Output::getInstance();

        $talkline = Translator::translateInline($talkline, Translator::getInstance()->getSchema());
        $youhave = Translator::translateInline('You have ');
        $charsleft = Translator::translateInline(' characters left.');
        $startdiv = $startdiv === false ? '' : $startdiv;

        $encodedStart = addslashes($output->appoencode($startdiv));
        $maxChars = (int) Settings::getInstance()->getSetting('maxchars', 200);

        $script = <<<JS
<script language="JavaScript">
function previewtext{$name}(t, l) {
    var out = "<span class='colLtWhite'>{$encodedStart} ";
    var end = '</span>';
    var x = 0;
    var y = '';
    var z = '';
    var max = document.getElementById('input{$name}');
    var charsleft = '';
JS;

        if ($talkline !== false) {
            $script .= <<<JS

    if (t.substr(0, 2) == '::') {
        x = 2;
        out += '</span><span class="colLtWhite">';
    } else if (t.substr(0, 1) == ':') {
        x = 1;
        out += '</span><span class="colLtWhite">';
    } else if (t.substr(0, 3) == '/me') {
        x = 3;
        out += '</span><span class="colLtWhite">';
JS;
            if ($session['user']['superuser'] & SU_IS_GAMEMASTER) {
                $script .= <<<JS

    } else if (t.substr(0, 5) == '/game') {
        x = 5;
        out = '<span class="colLtWhite">';
JS;
            }
            $script .= <<<JS

    } else {
        out += '</span><span class="colDkCyan">{$talkline}, </span><span class="colLtCyan">';
        end += '</span><span class="colDkCyan">';
    }
JS;
        }

        if ($showcharsleft) {
            $script .= <<<JS

    if (x != 0) {
        if (max.maxLength != {$maxChars}) {
            max.maxLength = {$maxChars};
        }
        l = {$maxChars};
    } else {
        max.maxLength = l;
    }
    if (l - t.length < 0) {
        charsleft += '<span class="colLtRed">';
    }
    charsleft += '{$youhave}' + (l - t.length) + '{$charsleft}<br>';
    if (l - t.length < 0) {
        charsleft += '</span>';
    }
    document.getElementById('charsleft{$name}').innerHTML = charsleft + '<br/>';
JS;
        }

        $switchscript = DataCache::getInstance()->datacache('switchscript_comm' . rawurlencode($name));

        if (!$switchscript) {
            $colors = $output->getColors();
            $cases = ["case \"0\": out+='</span>';break;"];
            foreach ($colors as $key => $colorcode) {
                $cases[] = "case \"{$key}\": out+='</span><span class=\"{$colorcode}\">';break;";
            }
            $cases = implode("\n            ", $cases);

            $switchscript = <<<JS

    for (; x < t.length; x++) {
        y = t.substr(x, 1);
        if (y == '<') {
            out += '&lt;';
            continue;
        } else if (y == '>') {
            out += '&gt;';
            continue;
        } else if (y == '`') {
            if (x < t.length - 1) {
                z = t.substr(x + 1, 1);
                switch (z) {
                        {$cases}
                }
                x++;
            }
        } else {
            out += y;
        }
    }
    document.getElementById("previewtext{$name}").innerHTML = out + end + '<br/>';
}
</script>
JS;

            DataCache::getInstance()->updatedatacache('switchscript_comm' . rawurlencode($name), $switchscript);
        }

        $script .= $switchscript;

        if ($showcharsleft) {
            $script .= "<span id='charsleft{$name}'></span>";
        }

        if (!is_array($info)) {
            $script .= "<input name='{$name}' id='input{$name}' maxsize='" . ($maxChars + 100) . "' onKeyUp='previewtext{$name}(document.getElementById(\"input{$name}\").value,{$maxChars});'>";
        } else {
            $l = isset($info['maxlength']) ? $info['maxlength'] : $maxChars;
            if (isset($info['type']) && $info['type'] == 'textarea') {
                $script .= "<textarea name='{$name}' id='input{$name}' onKeyUp='previewtext{$name}(document.getElementById(\"input{$name}\").value,{$l});' ";
            } else {
                $script .= "<input name='{$name}' id='input{$name}' onKeyUp='previewtext{$name}(document.getElementById(\"input{$name}\").value,{$l});' ";
            }
            foreach ($info as $key => $val) {
                $script .= "$key='$val'";
            }
            if (isset($info['type']) && $info['type'] == 'textarea') {
                $script .= '></textarea>';
            } else {
                $script .= '>';
            }
        }

        $add = Translator::translateInline('Add');
        $returnscript = $script . "<div id='previewtext{$name}'></div>";
        $script .= "<input type='submit' class='button' value='{$add}'><br>";
        $script .= "<div id='previewtext{$name}'></div>";

        if ($scriptOutput) {
            $output->rawOutput($script);
        }

        return $returnscript;
    }
    /**
     * Render a form described by the given layout array.
     */
    public static function showForm(array $layout, array $row, bool $nosave = false, string|bool $keypref = false): array
    {
        global $session;

        static $showform_id = 0;
        static $title_id = 0;

        $showform_id++;
        $formSections = [];
        $returnvalues = [];
        $extensions = HookHandler::hook('showformextensions', []);
        $output = Output::getInstance();

        $output->rawOutput("<table width='100%' cellpadding='0' cellspacing='0'><tr><td>");
        $output->rawOutput("<div id='showFormSection$showform_id'></div>");
        $output->rawOutput("</td></tr><tr><td>&nbsp;</td></tr><tr><td>");
        $output->rawOutput("<table cellpadding='2' cellspacing='0'>");

        $i = 0;
        foreach ($layout as $key => $val) {
            self::renderLayoutEntry(
                (string) $key,
                $val,
                $row,
                $keypref,
                $returnvalues,
                $extensions,
                $title_id,
                $i,
                $formSections
            );
        }

        $output->rawOutput("</table><br>");

        if ($showform_id == 1) {
            $startIndex = (int) Http::post('showFormTabIndex');
            if ($startIndex == 0) {
                $startIndex = 1;
            }
        } else {
            $startIndex = 1;
        }

        $tabDisabled = isset($session['user']['prefs']['tabconfig']) &&
            $session['user']['prefs']['tabconfig'] == 0;
        self::setupTabs($showform_id, $formSections, $startIndex, $tabDisabled);

        $output->rawOutput("</td></tr></table>");
        Translator::getInstance()->setSchema('showform');
        $save = Translator::translateInline('Save');
        Translator::getInstance()->setSchema();
        if (!$nosave) {
            $output->rawOutput("<input type='submit' class='button' value='$save'>");
        }

        return $returnvalues;
    }

    /**
     * Render a single entry of the layout array.
     */
    private static function renderLayoutEntry(
        string $key,
        mixed $val,
        array $row,
        string|bool $keypref,
        array &$returnvalues,
        array $extensions,
        int &$titleId,
        int &$rowIndex,
        array &$formSections
    ): void {
        $keyout = $keypref !== false ? sprintf($keypref, $key) : $key;

        if (is_array($val)) {
            $v = $val[0];
            $info = explode(',', $v);
            $val[0] = $info[0];
            $info[0] = $val;
        } else {
            $info = explode(',', (string) $val);
        }

        if (is_array($info[0])) {
            $info[0] = Translator::sprintfTranslate(...$info[0]);
        } else {
            $info[0] = Translator::translate($info[0]);
        }

        $info[1] = isset($info[1]) ? trim((string) $info[1]) : '';

        $output = Output::getInstance();

        if ($info[1] == 'title') {
            $titleId++;
            $output->rawOutput('</table>');
            $formSections[$titleId] = $info[0];
            $output->rawOutput("<table id='showFormTable$titleId' cellpadding='2' cellspacing='0'>");
            $output->rawOutput("<tr><td colspan='2' class='trhead'>");
            $output->outputNotl("`b%s`b", $info[0], true);
            $output->rawOutput('</td></tr>');
            $rowIndex = 0;
            return;
        }

        if ($info[1] == 'note') {
            $output->rawOutput("<tr class='" . ($rowIndex % 2 ? 'trlight' : 'trdark') . "'><td colspan='2'>");
            $output->outputNotl("`i%s`i", $info[0], true);
            $rowIndex++;
            $output->rawOutput('</td></tr>');
            return;
        }

        if (isset($row[$key])) {
            $returnvalues[$key] = $row[$key];
        }

        $fieldId = 'form-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $keyout);
        $settings = Settings::getInstance();
        $charset  = $settings->getSetting('charset', 'UTF-8');

        $entityFieldId = HTMLEntities($fieldId, ENT_QUOTES, $charset);

        $output->rawOutput("<tr class='" . ($rowIndex % 2 ? 'trlight' : 'trdark') . "'><td class='formfield-label' valign='top'>");
        $output->rawOutput("<label for='$entityFieldId'>");
        $output->outputNotl('%s', $info[0], true);
        $output->rawOutput("</label></td><td class='formfield-value' valign='top'>");
        $rowIndex++;

        self::renderField($keyout, $key, $info, $row, $returnvalues, $extensions, $fieldId, $entityFieldId);

        $output->rawOutput('</td></tr>');
    }

    /**
     * Render a single field element.
     */
    private static function renderField(
        string $keyout,
        string $key,
        array $info,
        array $row,
        array &$returnvalues,
        array $extensions,
        string $fieldId,
        string $entityId
    ): void {
        $pretrans = 0;
        $settings = Settings::getInstance();
        $charset  = $settings->getSetting('charset', 'UTF-8');
        $output   = Output::getInstance();
        switch ($info[1]) {
            case 'theme':
                $skins = Template::getAvailableTemplates();

                if (count($skins) == 0) {
                    $output->output('None available');
                    break;
                }

                asort($skins, SORT_NATURAL | SORT_FLAG_CASE);
                $current = isset($row[$key]) ? Template::addTypePrefix($row[$key]) : '';

                $output->rawOutput("<select id='$entityId' name='" . htmlentities($keyout, ENT_QUOTES, $charset) . "'>");
                $output->rawOutput("<option value=''" . ($current === '' ? ' selected' : '') . '>---</option>');
                foreach ($skins as $skin => $display) {
                    $display = htmlentities($display, ENT_COMPAT, $charset);
                    $skinEsc = htmlentities($skin, ENT_QUOTES, $charset);
                    if ($skin == $current) {
                        $output->rawOutput("<option value='$skinEsc' selected>$display</option>");
                    } else {
                        $output->rawOutput("<option value='$skinEsc'>$display</option>");
                    }
                }
                $output->rawOutput('</select>');
                break;

            case 'location':
                $vloc = [];
                $vname = $settings->getSetting('villagename', LOCATION_FIELDS);
                $vloc[$vname] = 'village';
                $vloc['all'] = 1;
                $vloc = HookHandler::hook('validlocation', $vloc);
                unset($vloc['all']);
                reset($vloc);
                $output->rawOutput("<select id='$entityId' name='$keyout'>");
                foreach ($vloc as $loc => $val) {
                    if ($loc == $row[$key]) {
                        $output->rawOutput("<option value='$loc' selected>" . htmlentities($loc, ENT_COMPAT, $charset) . '</option>');
                    } else {
                        $output->rawOutput("<option value='$loc'>" . htmlentities($loc, ENT_COMPAT, $charset) . '</option>');
                    }
                }
                $output->rawOutput('</select>');
                break;

            case 'checkpretrans':
                $pretrans = 1;
                // no break
            case 'checklist':
                $inf_list = $info;
                array_shift($inf_list);
                array_shift($inf_list);
                $select = '';
                while ($optval = array_shift($inf_list)) {
                    $optdis = array_shift($inf_list);
                    if (!$pretrans) {
                        $optdis = Translator::translateInline($optdis);
                    }
                    if (is_array($row[$key])) {
                        $checked = $row[$key][$optval] ? true : false;
                    } else {
                        Output::getInstance()->debug('You must pass an array as the value when using a checklist.');
                        $checked = false;
                    }
                    $id = HTMLEntities("{$fieldId}-{$optval}", ENT_QUOTES, $charset);
                    $select .= "<input id='$id' type='checkbox' name='{$keyout}[{$optval}]' value='1'" . ($checked ? ' checked' : '') . ">&nbsp;" . ($optdis) . '<br>';
                }
                $output->rawOutput($select);
                break;

            case 'radiopretrans':
                $pretrans = 1;
                // no break
            case 'radio':
                $inf_list = $info;
                array_shift($inf_list);
                array_shift($inf_list);
                $select = '';
                while ($optval = array_shift($inf_list)) {
                    $optdis = array_shift($inf_list);
                    if (!$pretrans) {
                        $optdis = Translator::translateInline($optdis);
                    }
                    $id = HTMLEntities("{$fieldId}-{$optval}", ENT_QUOTES, $charset);
                    $select .= "<input id='$id' type='radio' name='$keyout' value='$optval'" . ($row[$key] == $optval ? ' checked' : '') . ">&nbsp;" . ($optdis) . '<br>';
                }
                $output->rawOutput($select);
                break;

            case 'dayrange':
                $start = strtotime(date('Y-m-d', strtotime('now')));
                $end = strtotime($info[2]);
                $step = $info[3];
                $cur = $row[$key];
                $output->rawOutput("<select id='$entityId' name='$keyout'>");
                if ($cur && $cur < date('Y-m-d H:i:s', $start)) {
                    $output->rawOutput("<option value='$cur' selected>" . htmlentities($cur, ENT_COMPAT, $charset) . '</option>');
                }
                for ($j = $start; $j < $end; $j = strtotime($step, $j)) {
                    $d = date('Y-m-d H:i:s', $j);
                    $output->rawOutput("<option value='$d'" . ($cur == $d ? ' selected' : '') . '>' . HTMLEntities("$d", ENT_COMPAT, $charset) . '</option>');
                }
                if ($cur && $cur > date('Y-m-d H:i:s', $end)) {
                    $output->rawOutput("<option value='$cur' selected>" . htmlentities($cur, ENT_COMPAT, $charset) . '</option>');
                }
                $output->rawOutput('</select>');
                break;

            case 'range':
                $min = (int) $info[2];
                $max = (int) $info[3];
                $step = round((float) (isset($info[4]) ? $info[4] : 1), 2);
                if ($step == 0) {
                    $step = 1;
                }
                $output->rawOutput("<select id='$entityId' name='$keyout'>");
                if ($min < $max && ($max - $min) / $step > 300) {
                    $step = max(1, (int) (($max - $min) / 300));
                }
                for ($j = $min; $j <= $max; $j += $step) {
                    $output->rawOutput("<option value='$j'" . ((isset($row[$key]) ? $row[$key] : '') == $j ? ' selected' : '') . '>' . HTMLEntities("$j", ENT_COMPAT, $charset) . '</option>');
                }
                $output->rawOutput('</select>');
                break;

            case 'floatrange':
                $min = round((float) $info[2], 2);
                $max = round((float) $info[3], 2);
                $step = round((float) (isset($info[4]) ? $info[4] : 1), 2);
                if ($step == 0) {
                    $step = 1;
                }
                $output->rawOutput("<select id='$entityId' name='$keyout'>");
                $val = round((float) $row[$key], 2);
                for ($j = $min; $j <= $max; $j = round($j + $step, 2)) {
                    $output->rawOutput("<option value='$j'" . ($val == $j ? ' selected' : '') . '>' . HTMLEntities("$j", ENT_COMPAT, $charset) . '</option>');
                }
                $output->rawOutput('</select>');
                break;

            case 'bitfieldpretrans':
                $pretrans = 1;
                // no break
            case 'bitfield':
                $inf_list = $info;
                array_shift($inf_list);
                array_shift($inf_list);
                $disablemask = array_shift($inf_list);
                $output->rawOutput("<input type='hidden' name='$keyout" . "[0]' value='1'>");
                while ($v = array_shift($inf_list)) {
                    $id = HTMLEntities("{$fieldId}-{$v}", ENT_QUOTES, $charset);
                    $output->rawOutput("<input id='$id' type='checkbox' name='$keyout" . "[$v]'" .
                        ((int) $row[$key] & (int) $v ? ' checked' : '') .
                        ($disablemask & (int) $v ? '' : ' disabled') .
                        " value='1'> ");
                    $v = array_shift($inf_list);
                    if (!$pretrans) {
                        $v = Translator::translateInline($v);
                    }
                    $output->outputNotl('%s`n', $v, true);
                }
                break;

            case 'datelength':
                $vals = [
                    '1 hour', '2 hours', '3 hours', '4 hours',
                    '5 hours', '6 hours', '8 hours', '10 hours',
                    '12 hours', '16 hours', '18 hours', '24 hours',
                    '1 day', '2 days', '3 days', '4 days', '5 days',
                    '6 days', '7 days',
                    '1 week', '2 weeks', '3 weeks', '4 weeks',
                    '1 month', '2 months', '3 months', '4 months',
                    '6 months', '9 months', '12 months',
                    '1 year'
                ];
                Translator::getInstance()->setSchema('showform');
                foreach ($vals as $k => $v) {
                    $vals[$k] = Translator::translate($v);
                    $output->rawOutput(Translator::tlbuttonPop());
                }
                Translator::getInstance()->setSchema();
                $output->rawOutput("<select id='$entityId' name='$keyout'>");
                foreach ($vals as $k => $v) {
                    $output->rawOutput("<option value=\"" . htmlentities($v, ENT_COMPAT, $charset) . "\"" . ($row[$key] == $v ? ' selected' : '') . '>' . htmlentities($v, ENT_COMPAT, $charset) . '</option>');
                }
                $output->rawOutput('</select>');
                break;

            case 'enumpretrans':
                $pretrans = 1;
                // no break
            case 'enum':
                $inf_list = $info;
                array_shift($inf_list);
                array_shift($inf_list);
                $select = '';
                $select .= "<select id='$entityId' name='$keyout'>";
                $optval = '';
                foreach ($inf_list as $optdis) {
                    if ($optval == '') {
                        $optval = $optdis;
                        continue;
                    }
                    if (!$pretrans) {
                        $optdis = Translator::translateInline($optdis);
                    }
                    $selected = isset($row[$key]) && $row[$key] == $optval ? 1 : 0;
                    $select .= "<option value='$optval'" . ($selected ? ' selected' : '') . '>' . HTMLEntities("$optdis", ENT_COMPAT, $charset) . '</option>';
                    $optval = '';
                }
                $select .= '</select>';
                $output->rawOutput($select);
                break;

            case 'password':
                $out = array_key_exists($key, $row) ? $row[$key] : '';
                $output->rawOutput("<input id='$entityId' type='password' name='$keyout' value='" . HTMLEntities($out, ENT_COMPAT, $charset) . "'>");
                break;

            case 'bool':
                Translator::getInstance()->setSchema('showform');
                $yes = Translator::translateInline('Yes');
                $no = Translator::translateInline('No');
                $boolval = isset($row[$key]) ? $row[$key] : 0;
                Translator::getInstance()->setSchema();
                $output->rawOutput("<select id='$entityId' name='$keyout'>");
                $output->rawOutput("<option value='0'" . ($boolval == 0 ? ' selected' : '') . ">$no</option>");
                $output->rawOutput("<option value='1'" . ($boolval == 1 ? ' selected' : '') . ">$yes</option>");
                $output->rawOutput('</select>');
                break;

            case 'checkbox':
                $checked = !empty($row[$key]);
                $output->rawOutput("<input id='$entityId' type='checkbox' name='$keyout' value='1'" . ($checked ? ' checked' : '') . '>');
                break;

             case 'hidden':
                  $output->rawOutput(
                        "<input id='$entityId' type='hidden' name='$keyout' value=\"" 
                        . htmlentities((string)($row[$key] ?? ''), ENT_COMPAT, $charset)
                        . "\">" 
                        . htmlentities((string)($row[$key] ?? ''), ENT_COMPAT, $charset)
                    );
                break;

            case 'viewonly':
                unset($returnvalues[$key]);
                if (isset($row[$key])) {
                    $output->outputNotl(DumpItem::dump($row[$key]), true);
                }
                break;

            case 'viewhiddenonly':
                if (isset($row[$key])) {
                    $row[$key] = (string) $row[$key];
                    $output->outputNotl(DumpItem::dump($row[$key]), true);
                    $output->rawOutput("<input id='$entityId' type='hidden' name='" . addslashes($key) . "' value='" . addslashes($row[$key]) . "'>");
                }
                break;

            case 'rawtextarearesizeable':
                $raw = true;
                // no break
            case 'textarearesizeable':
                $resize = true;
                // no break
            case 'textarea':
                $cols = isset($info[2]) ? $info[2] : 0;
                if (!$cols) {
                    $cols = 70;
                }
                $text = isset($row[$key]) ? $row[$key] : '';
                if (!isset($raw) || !$raw) {
                    $text = str_replace('`n', "\n", $text);
                }
                if (isset($resize) && $resize) {
                    $output->rawOutput("<script type=\"text/javascript\">function increase(target, value){  if (target.rows + value > 3 && target.rows + value < 50) target.rows = target.rows + value;}</script>");
                    $output->rawOutput("<script type=\"text/javascript\">function cincrease(target, value){  if (target.cols + value > 3 && target.cols + value < 150) target.cols = target.cols + value;}</script>");
                    $output->rawOutput("<input type='button' onClick=\"increase(document.getElementById('$entityId'),1);\" value='+' accesskey='+'><input type='button' onClick=\"increase(document.getElementById('$entityId'),-1);\" value='-' accesskey='-'>");
                    $output->rawOutput("<input type='button' onClick=\"cincrease(document.getElementById('$entityId'),-1);\" value='<-'><input type='button' onClick=\"cincrease(document.getElementById('$entityId'),1);\" value='->' accesskey='-'><br>");
                    $output->rawOutput("<textarea id='$entityId' class='input' name='$keyout' cols='$cols' rows='5'>" . htmlentities($text, ENT_COMPAT, $charset) . '</textarea>');
                } else {
                    $output->rawOutput("<textarea id='$entityId' class='input' name='$keyout' cols='$cols' rows='5'>" . htmlentities($text, ENT_COMPAT, $charset) . '</textarea>');
                }
                break;

            case 'int':
                $out = (string) (array_key_exists($key, $row) ? $row[$key] : 0);
                $output->rawOutput("<input id='$entityId' name='$keyout' value=\"" . HTMLEntities($out, ENT_COMPAT, $charset) . "\" size='5'>");
                break;

            case 'float':
                $output->rawOutput("<input id='$entityId' name='$keyout' value=\"" . htmlentities($row[$key], ENT_COMPAT, $charset) . "\" size='8'>");
                break;

            case 'string':
                $len = isset($info[2]) ? (int) $info[2] : 50;
                $minlen = $len;
                if ($len < $minlen) {
                    $minlen = $len;
                }
                if ($len > $minlen) {
                    $minlen = $len / 2;
                }
                if ($minlen > 70) {
                    $minlen = 70;
                }
                $val = array_key_exists($key, $row) ? $row[$key] : '';
                $output->rawOutput("<input id='$entityId' size='$minlen' maxlength='$len' name='$keyout' value=\"" . HTMLEntities($val, ENT_COMPAT, $charset) . "\">");
                break;

            default:
                if (array_key_exists($info[1], $extensions)) {
                    $func = $extensions[$info[1]];
                    $val = array_key_exists($key, $row) ? $row[$key] : '';
                    $func($keyout, $val, $info);
                } else {
                    $val = array_key_exists($key, $row) ? $row[$key] : '';
                    if (!is_string($val)) {
                        $val = (string) $val;
                    }
                    $output->rawOutput("<input id='$entityId' size='50' name='$keyout' value=\"" . HTMLEntities($val, ENT_COMPAT, $charset) . "\">");
                }
        }
    }

    /**
     * Output tab handling JavaScript for the form.
     */
    private static function setupTabs(int $formId, array $sections, int $startIndex, bool $disabled): void
    {
        if ($disabled) {
            return;
        }

        $output = Output::getInstance();

        if ($formId == 1) {
            $output->rawOutput(
                "<script language='JavaScript'>
                function prepare_form(id){
                    var theTable;
                    var theDivs='';
                    var x=0;
                    var weight='';
                    for (x in formSections[id]){
                        theTable = document.getElementById('showFormTable'+x);
                        if (x != $startIndex ){
                            theTable.style.visibility='hidden';
                            theTable.style.display='none';
                            weight='';
                        }else{
                            theTable.style.visibility='visible';
                            theTable.style.display='inline';
                            weight='color: yellow;';
                        }
                        theDivs += \"<div id='showFormButton\"+x+\"' class='trhead' style='\"+weight+\"float: left; cursor: pointer; cursor: hand; padding: 5px; border: 1px solid #000000;' onClick='showFormTabClick(\"+id+\",\"+x+\");'>\"+formSections[id][x]+\"</div>\";
                    }
                    theDivs += \"<div style='display: block;'>&nbsp;</div>\";
                    theDivs += \"<input type='hidden' name='showFormTabIndex' value='$startIndex' id='showFormTabIndex'>\";
                    document.getElementById('showFormSection'+id).innerHTML = theDivs;
                }
                function showFormTabClick(formid,sectionid){
                    var theTable;
                    var theButton;
                    for (x in formSections[formid]){
                        theTable = document.getElementById('showFormTable'+x);
                        theButton = document.getElementById('showFormButton'+x);
                        if (x == sectionid){
                            theTable.style.visibility='visible';
                            theTable.style.display='inline';
                            theButton.style.fontWeight='normal';
                            theButton.style.color='yellow';
                            document.getElementById('showFormTabIndex').value = sectionid;
                        }else{
                            theTable.style.visibility='hidden';
                            theTable.style.display='none';
                            theButton.style.fontWeight='normal';
                            theButton.style.color='';
                        }
                    }
                }
                formSections = new Array();
                </script>"
            );
        }

        $output->rawOutput("<script language='JavaScript'>");
        $encodedSections = json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $output->rawOutput("formSections[$formId] = JSON.parse('$encodedSections');");
        $output->rawOutput("prepare_form($formId);</script>");
    }
}
