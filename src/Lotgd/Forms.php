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
        $currentSection = '';
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
                $formSections,
                $currentSection,
                false
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
     * Render a tabbed form described by the given layout array.
     */
    public static function showFormTabbed(array $layout, array $row, bool $nosave = false, string|bool $keypref = false): array
    {
        static $showform_id = 10000;
        static $title_id = 10000;

        $showform_id++;
        $formSections = [];
        $returnvalues = [];
        $extensions = HookHandler::hook('showformextensions', []);
        $output = Output::getInstance();

        $output->rawOutput("<table width='100%' cellpadding='0' cellspacing='0'><tr><td>");
        $output->rawOutput("<div id='showFormSection$showform_id'></div>");
        $output->rawOutput("</td></tr><tr><td>&nbsp;</td></tr><tr><td>");
        $settings = Settings::getInstance();
        $charset  = $settings->getSetting('charset', 'UTF-8');
        Translator::getInstance()->setSchema('showform');
        $labelHeader = Translator::translateInline('Label');
        $valueHeader = Translator::translateInline('Value');
        Translator::getInstance()->setSchema();
        $labelHeader = HTMLEntities($labelHeader, ENT_QUOTES, $charset);
        $valueHeader = HTMLEntities($valueHeader, ENT_QUOTES, $charset);

        $output->rawOutput("<table id='showFormTable$showform_id' role='tabpanel' cellpadding='2' cellspacing='0'>");
        $output->rawOutput("<thead class='visually-hidden'><tr><th class='formfield-label'>{$labelHeader}</th><th class='formfield-value'>{$valueHeader}</th></tr></thead>");
        $output->rawOutput('<tbody>');

        $i = 0;
        $currentSection = '';
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
                $formSections,
                $currentSection,
                true
            );
        }

        $output->rawOutput("</tbody></table><br>");

        if ($showform_id == 10001) {
            $startIndex = (int) Http::post('showFormTabIndex');
            if ($startIndex < 0) {
                $startIndex = 0;
            }
        } else {
            $startIndex = 0;
        }

        Translator::getInstance()->setSchema('showform');
        $allLabel = Translator::translateInline('All');
        Translator::getInstance()->setSchema();
        self::setupTabbedDataTable($showform_id, $formSections, $startIndex, $allLabel);

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
        array &$formSections,
        string &$currentSection,
        bool $singleTable
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
        $settings = Settings::getInstance();
        $charset  = $settings->getSetting('charset', 'UTF-8');

        if ($info[1] == 'title') {
            $titleId++;
            $formSections[$titleId] = $info[0];
            $currentSection = $info[0];
            $sectionAttribute = " data-section='" . HTMLEntities($currentSection, ENT_QUOTES, $charset) . "'";
            if (!$singleTable) {
                $output->rawOutput('</table>');
                $output->rawOutput("<table id='showFormTable$titleId' role='tabpanel' aria-labelledby='showFormTab$titleId' cellpadding='2' cellspacing='0'$sectionAttribute>");
            }
            $output->rawOutput("<tr$sectionAttribute class='trhead'><td class='formfield-label'>");
            $output->outputNotl("`b%s`b", $info[0], true);
            $output->rawOutput("</td><td class='formfield-value'></td></tr>");
            $rowIndex = 0;
            return;
        }

        if ($info[1] == 'note') {
            $sectionAttribute = '';
            if ($currentSection !== '') {
                $sectionAttribute = " data-section='" . HTMLEntities($currentSection, ENT_QUOTES, $charset) . "'";
            }
            $output->rawOutput("<tr$sectionAttribute class='" . ($rowIndex % 2 ? 'trlight' : 'trdark') . "'><td class='formfield-label'>");
            $output->outputNotl("`i%s`i", $info[0], true);
            $rowIndex++;
            $output->rawOutput("</td><td class='formfield-value'></td></tr>");
            return;
        }

        if (isset($row[$key])) {
            $returnvalues[$key] = $row[$key];
        }

        $fieldId = 'form-' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $keyout);

        $entityFieldId = HTMLEntities($fieldId, ENT_QUOTES, $charset);

        $sectionAttribute = '';
        if ($currentSection !== '') {
            $sectionAttribute = " data-section='" . HTMLEntities($currentSection, ENT_QUOTES, $charset) . "'";
        }
        $output->rawOutput("<tr$sectionAttribute class='" . ($rowIndex % 2 ? 'trlight' : 'trdark') . "'><td class='formfield-label' valign='top'>");
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
                $optval = null;
                foreach ($inf_list as $optdis) {
                    if ($optval === null) {
                        $optval = $optdis;
                        continue;
                    }
                    if (!$pretrans) {
                        $optdis = Translator::translateInline($optdis);
                    }
                    $selected = isset($row[$key]) && $row[$key] == $optval ? 1 : 0;
                    $select .= "<option value='$optval'" . ($selected ? ' selected' : '') . '>' . HTMLEntities("$optdis", ENT_COMPAT, $charset) . '</option>';
                    $optval = null;
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
        $tabListLabel = Translator::translateInline('Form sections');
        $encodedTabListLabel = json_encode($tabListLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        if ($formId == 1) {
            $output->rawOutput(
                "<script language='JavaScript'>
                function prepare_form(id){
                    var theTable;
                    var tabContainer = document.getElementById('showFormSection'+id);
                    if (!tabContainer) {
                        return;
                    }
                    tabContainer.innerHTML = '';
                    var tabList = document.createElement('div');
                    tabList.setAttribute('role', 'group');
                    tabList.setAttribute('aria-label', $encodedTabListLabel);
                    tabList.id = 'showFormTablist' + id;
                    tabContainer.appendChild(tabList);
                    for (var x in formSections[id]){
                        if (!Object.prototype.hasOwnProperty.call(formSections[id], x)) {
                            continue;
                        }
                        theTable = document.getElementById('showFormTable'+x);
                        if (x != $startIndex ){
                            theTable.style.visibility='hidden';
                            theTable.style.display='none';
                        }else{
                            theTable.style.visibility='visible';
                            theTable.style.display='inline';
                        }
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.id = 'showFormTab' + x;
                        button.className = 'trhead';
                        button.setAttribute('role', 'button');
                        button.setAttribute('aria-controls', 'showFormTable' + x);
                        button.setAttribute('aria-pressed', 'false');
                        button.dataset.sectionId = x;
                        button.style.cssText = 'float: left; cursor: pointer; padding: 5px; border: 1px solid #000000;';
                        button.appendChild(document.createTextNode(formSections[id][x]));
                        tabList.appendChild(button);
                    }
                    var spacer = document.createElement('div');
                    spacer.style.display = 'block';
                    spacer.innerHTML = '&nbsp;';
                    tabContainer.appendChild(spacer);
                    var hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'showFormTabIndex';
                    hidden.value = '$startIndex';
                    hidden.id = 'showFormTabIndex-' + id;
                    tabContainer.appendChild(hidden);
                    tabList.addEventListener('keydown', function (event) {
                        showFormTabKeydown(event, id);
                    });
                    showFormTabClick(id, $startIndex);
                }
                function showFormTabClick(formid,sectionid){
                    var theTable;
                    var theButton;
                    for (var x in formSections[formid]){
                        if (!Object.prototype.hasOwnProperty.call(formSections[formid], x)) {
                            continue;
                        }
                        theTable = document.getElementById('showFormTable'+x);
                        theButton = document.getElementById('showFormTab'+x);
                        theTable.setAttribute('aria-labelledby', theButton.id);
                        if (x == sectionid){
                            theTable.style.visibility='visible';
                            theTable.style.display='inline';
                            theButton.style.color='yellow';
                            theButton.setAttribute('aria-pressed', 'true');
                            theButton.tabIndex = 0;
                            theTable.setAttribute('aria-labelledby', theButton.id);
                            document.getElementById('showFormTabIndex-' + formid).value = sectionid;
                        }else{
                            theTable.style.visibility='hidden';
                            theTable.style.display='none';
                            theButton.style.color='';
                            theButton.setAttribute('aria-pressed', 'false');
                            theButton.tabIndex = -1;
                        }
                    }
                }
                function showFormTabKeydown(event, formid){
                    var keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
                    if (keys.indexOf(event.key) === -1) {
                        return;
                    }
                    var tabList = document.getElementById('showFormTablist' + formid);
                    if (!tabList) {
                        return;
                    }
                    var tabs = tabList.querySelectorAll('[role=\"button\"]');
                    if (!tabs.length) {
                        return;
                    }
                    var currentIndex = 0;
                    for (var i = 0; i < tabs.length; i++) {
                        if (tabs[i] === document.activeElement) {
                            currentIndex = i;
                            break;
                        }
                    }
                    var nextIndex = currentIndex;
                    if (event.key === 'ArrowLeft') {
                        nextIndex = (currentIndex - 1 + tabs.length) % tabs.length;
                    }
                    if (event.key === 'ArrowRight') {
                        nextIndex = (currentIndex + 1) % tabs.length;
                    }
                    if (event.key === 'Home') {
                        nextIndex = 0;
                    }
                    if (event.key === 'End') {
                        nextIndex = tabs.length - 1;
                    }
                    event.preventDefault();
                    tabs[nextIndex].focus();
                    showFormTabClick(formid, tabs[nextIndex].dataset.sectionId);
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

    /**
     * Output tab handling JavaScript for a tabbed form rendered as a single table.
     */
    private static function setupTabbedDataTable(
        int $formId,
        array $sections,
        int $startIndex,
        string $allLabel
    ): void {
        $output = Output::getInstance();
        $tabListLabel = Translator::translateInline('Form sections');
        $searchLabel = Translator::translateInline('Search');
        $encodedSections = json_encode($sections, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $encodedAllLabel = json_encode($allLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $encodedTabListLabel = json_encode($tabListLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $encodedSearchLabel = json_encode($searchLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $output->rawOutput("<script language='JavaScript'>");
        $output->rawOutput("window.formSections = window.formSections || [];");
        $output->rawOutput("formSections[$formId] = JSON.parse('$encodedSections');");
        $output->rawOutput("
            (function () {
                if (typeof jQuery === 'undefined') {
                    return;
                }
                var \$table = jQuery('#showFormTable$formId');
                if (!\$table.length) {
                    return;
                }
                var activeSection = '';
                var useDataTable = typeof jQuery.fn.DataTable !== 'undefined';
                var tableApi = null;
                if (useDataTable) {
                    tableApi = \$table.DataTable({
                        dom: 't',
                        paging: false,
                        info: false,
                        ordering: false,
                        searching: true,
                        drawCallback: function () {
                            var api = this.api();
                            var \$rows = jQuery(api.rows({ filter: 'applied' }).nodes());
                            var visibleIndex = 0;
                            \$rows.removeClass('trlight trdark');
                            \$rows.each(function () {
                                var \$row = jQuery(this);
                                if (\$row.hasClass('trhead')) {
                                    return;
                                }
                                var stripeClass = (visibleIndex % 2 === 0) ? 'trlight' : 'trdark';
                                \$row.addClass(stripeClass);
                                visibleIndex++;
                            });
                        }
                    });
                    var globalSearch = '';
                    \$table.on('search.dt', function () {
                        globalSearch = tableApi.search();
                    });
                    jQuery.fn.dataTable.ext.search.push(function (settings, data, dataIndex) {
                        if (settings.nTable !== \$table[0]) {
                            return true;
                        }
                        if (!activeSection) {
                            return true;
                        }
                        var row = settings.aoData[dataIndex].nTr;
                        return jQuery(row).data('section') === activeSection;
                    });
                }
                function setActiveTab(sectionId, sectionName) {
                    activeSection = sectionName || '';
                    if (useDataTable) {
                        var currentSearch = tableApi.search();
                        if (globalSearch !== currentSearch) {
                            globalSearch = currentSearch;
                        }
                        tableApi.search(globalSearch).draw();
                    } else {
                        var \$rows = \$table.find('tr');
                        if (!activeSection) {
                            \$rows.show();
                        } else {
                            \$rows.hide();
                            \$rows.filter(function () {
                                return jQuery(this).data('section') === activeSection;
                            }).show();
                        }
                    }
                    if (\$tabInput.length) {
                        \$tabInput.val(sectionId);
                    }
                    jQuery('[data-showform-tab=\"$formId\"]').css('color', '');
                    jQuery('[data-showform-tab=\"$formId\"]').attr('aria-pressed', 'false').attr('tabindex', '-1');
                    var \$activeTab = jQuery('#showFormTab' + sectionId);
                    \$activeTab.css('color', 'yellow');
                    \$activeTab.attr('aria-pressed', 'true').attr('tabindex', '0');
                    \$table.attr('aria-labelledby', 'showFormTab' + sectionId);
                }
                var \$tabsContainer = jQuery('#showFormSection$formId');
                var tabInputSelector = \"input[name='showFormTabIndex[$formId]']\";
                var \$tabInput = jQuery();
                \$tabsContainer.empty();
                var \$searchWrapper = jQuery('<div/>', {
                    id: 'showFormTable$formId' + '_search',
                    'class': 'datatable-search'
                }).css({
                    display: 'block',
                    margin: '0 0 6px 0'
                });
                var \$searchInput = jQuery('<input/>', {
                    type: 'search',
                    id: 'showFormTable$formId' + '_search_input',
                    placeholder: $encodedSearchLabel,
                    'aria-label': $encodedSearchLabel
                }).css({
                    display: 'block',
                    width: '100%',
                    maxWidth: '320px'
                });
                if (useDataTable) {
                    \$searchInput.on('keyup', function () {
                        tableApi.search(this.value).draw();
                    });
                    \$searchWrapper.append(\$searchInput);
                }
                var \$tabList = jQuery('<div/>', {
                    role: 'group',
                    'aria-label': $encodedTabListLabel,
                    id: 'showFormTablist$formId'
                });
                function appendTab(sectionId, label, sectionName) {
                    var \$tab = jQuery('<button/>', {
                        id: 'showFormTab' + sectionId,
                        type: 'button',
                        'class': 'trhead',
                        'data-showform-tab': '$formId',
                        'data-section-id': sectionId,
                        role: 'button',
                        'aria-pressed': 'false'
                    })
                        .css({
                            float: 'left',
                            cursor: 'pointer',
                            padding: '5px',
                            border: '1px solid #000000'
                        })
                        .text(label)
                        .attr('data-section', sectionName || '');
                    \$tabList.append(\$tab);
                }
                var allLabel = $encodedAllLabel;
                appendTab(0, allLabel, '');
                for (var key in formSections[$formId]) {
                    if (!Object.prototype.hasOwnProperty.call(formSections[$formId], key)) {
                        continue;
                    }
                    appendTab(key, formSections[$formId][key], formSections[$formId][key]);
                }
                if (\$searchWrapper.children().length) {
                    \$tabsContainer.append(\$searchWrapper);
                }
                \$tabsContainer.append(\$tabList);
                \$tabsContainer.append(\"<div style='display: block;'>&nbsp;</div>\");
                \$tabsContainer.append(\"<input type='hidden' name='showFormTabIndex[$formId]' value='$startIndex' id='showFormTabIndex-$formId'>\");
                \$tabInput = \$tabsContainer.find(tabInputSelector);
                var initialSection = '';
                if ($startIndex && formSections[$formId][$startIndex]) {
                    initialSection = formSections[$formId][$startIndex];
                }
                setActiveTab($startIndex, initialSection);
                \$tabsContainer.on('click', '[data-showform-tab=\"$formId\"]', function () {
                    var sectionId = jQuery(this).data('section-id');
                    var sectionName = jQuery(this).data('section') || '';
                    setActiveTab(sectionId, sectionName);
                });
                \$tabsContainer.on('keydown', '[data-showform-tab=\"$formId\"]', function (event) {
                    var keys = ['ArrowLeft', 'ArrowRight', 'Home', 'End'];
                    if (keys.indexOf(event.key) === -1) {
                        return;
                    }
                    var \$tabs = \$tabList.find('[data-showform-tab=\"$formId\"]');
                    if (!\$tabs.length) {
                        return;
                    }
                    var index = \$tabs.index(this);
                    var nextIndex = index;
                    if (event.key === 'ArrowLeft') {
                        nextIndex = (index - 1 + \$tabs.length) % \$tabs.length;
                    }
                    if (event.key === 'ArrowRight') {
                        nextIndex = (index + 1) % \$tabs.length;
                    }
                    if (event.key === 'Home') {
                        nextIndex = 0;
                    }
                    if (event.key === 'End') {
                        nextIndex = \$tabs.length - 1;
                    }
                    event.preventDefault();
                    var \$nextTab = \$tabs.eq(nextIndex);
                    \$nextTab.focus();
                    var sectionId = \$nextTab.data('section-id');
                    var sectionName = \$nextTab.data('section') || '';
                    setActiveTab(sectionId, sectionName);
                });
            })();
        ");
        $output->rawOutput("</script>");
    }
}
